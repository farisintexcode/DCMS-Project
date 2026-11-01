<?php

use App\Actions\Imports\ImportWorkflow;
use App\Enums\BatchStatus;
use App\Jobs\ProcessImport;
use App\Livewire\Admin\Imports\Registry;
use App\Livewire\Admin\Imports\Review;
use App\Models\AssignmentBatch;
use App\Models\AuditLog;
use App\Models\ColumnAlias;
use App\Models\Debtor;
use App\Models\ImportBatch;
use App\Models\ImportField;
use App\Models\ImportTemplate;
use App\Models\OutboxEvent;
use App\Models\ProcessedEvent;
use App\Models\User;
use App\Services\Imports\MalwareScanner;
use App\Services\Imports\WorkbookReader;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(LazilyRefreshDatabase::class);
beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(DatabaseSeeder::class);
    config(['filesystems.disks.s3.bucket' => 'synthetic-imports']);
    Storage::fake('s3');
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('scan')->andReturnNull();
    app()->instance(MalwareScanner::class, $scanner);
});
function importFile(array $rows, string $format = 'xlsx'): UploadedFile
{
    $book = new Spreadsheet;
    $book->getActiveSheet()->setTitle('Intake');
    $book->getActiveSheet()->fromArray($rows);
    $path = tempnam(sys_get_temp_dir(), 'import-fixture-');
    ($format === 'xls' ? new Xls($book) : new Xlsx($book))->save($path);
    $book->disconnectWorksheets();
    $file = new UploadedFile($path, 'synthetic.'.$format, null, null, true);

    return $file;
}
function stagedImport(User $actor, array $rows, string $kind = 'customer', string $format = 'xlsx'): ImportBatch
{
    $file = importFile($rows, $format);
    try {
        $batch = app(ImportWorkflow::class)->upload($actor, ['kind' => $kind, 'template_id' => ImportTemplate::query()->where('kind', $kind)->firstOrFail()->id], $file);
    } finally {
        @unlink($file->getRealPath());
    }
    app(ImportWorkflow::class)->scan($batch->id);
    $batch->refresh();
    expect($batch->status)->toBe('mapping');
    app(ImportWorkflow::class)->map($actor, $batch, 'Intake', 1);

    return $batch->refresh();
}
function mappedSelections(ImportBatch $batch): array
{
    return $batch->mappings()->get()->mapWithKeys(fn ($mapping) => [$mapping->id => ['field_id' => $mapping->field_id ? (string) $mapping->field_id : 'ignore', 'transform' => 'exact']])->all();
}
function readyImport(User $actor, array $rows, string $kind = 'customer'): ImportBatch
{
    $batch = stagedImport($actor, $rows, $kind);
    app(ImportWorkflow::class)->preview($actor, $batch, mappedSelections($batch));

    return $batch->refresh();
}

test('customer workbook preview is encrypted non-writing and commit is atomic replay safe and reconciled', function (): void {
    $actor = staffManager();
    $batch = readyImport($actor, [['customer_reference', 'party_type', 'name', 'status', 'identity'], ['000123', 'person', 'Synthetic Person', 'active', '001234'], ['000124', 'person', '', 'active', ''], ['000123', 'person', 'Duplicate Person', 'active', '']]);
    expect($batch->accepted_count)->toBe(1)->and($batch->rejected_count)->toBe(1)->and($batch->duplicate_count)->toBe(1);
    $this->assertDatabaseCount('debtors', 0);
    expect(DB::table('import_rows')->value('raw_payload_ciphertext'))->not->toContain('Synthetic Person');
    expect(DB::table('audit_logs')->where('module', 'M13')->count())->toBe(0);
    expect(Storage::disk('s3')->get($batch->source->object_key))->not->toContain('Synthetic');
    app(ImportWorkflow::class)->confirm($actor, $batch, $batch->mapping_digest);
    app(ImportWorkflow::class)->process($batch->id);
    app(ImportWorkflow::class)->process($batch->id);
    $batch->refresh();
    expect($batch->status)->toBe('completed')->and($batch->processed_count)->toBe(1)->and($batch->failed_count)->toBe(0);
    expect(Debtor::query()->firstOrFail()->customer_reference)->toBe('000123');
    $this->assertDatabaseCount('debtors', 1);
    $this->assertDatabaseCount('notifications', 1);
    expect($batch->rows()->where('state', 'processed')->firstOrFail()->target_customer_id)->not->toBeNull();
});

test('batch imports use existing validations preserve identifiers and cannot commit stale target revisions', function (): void {
    $actor = staffManager();
    $rows = [['external_reference', 'name', 'type_code', 'start_date', 'due_date'], ['000001', 'Synthetic Batch', 'domestic_dr', '2026-10-01', '2026-10-31']];
    $batch = readyImport($actor, $rows, 'assignment_batch');
    app(ImportWorkflow::class)->confirm($actor, $batch, $batch->mapping_digest);
    app(ImportWorkflow::class)->process($batch->id);
    $target = AssignmentBatch::query()->firstOrFail();
    expect($target->external_reference)->toBe('000001');
    $rows[1][1] = 'Updated batch';
    $update = readyImport($actor, $rows, 'assignment_batch');
    $target->name = 'Concurrent edit';
    $target->lock_version++;
    $target->save();
    app(ImportWorkflow::class)->confirm($actor, $update, $update->mapping_digest);
    app(ImportWorkflow::class)->process($update->id);
    expect($update->refresh()->status)->toBe('failed')->and($target->refresh()->name)->toBe('Concurrent edit');
    app(ImportWorkflow::class)->retry($actor, $update);
    app(ImportWorkflow::class)->process($update->id);
    expect($update->refresh()->failed_count)->toBe(1)->and(AssignmentBatch::query()->count())->toBe(1);
});

test('matching reordered normalized headers preserves exact values and profiles are immutable new versions', function (): void {
    $actor = staffManager();
    $batch = stagedImport($actor, [["\xEF\xBB\xBFNAME", 'Status', 'Customer Reference', 'Party Type', 'Unused'], ['Synthetic Person', 'active', '000007', 'person', 'secret unused value']]);
    app(ImportWorkflow::class)->preview($actor, $batch, mappedSelections($batch));
    $batch->refresh();
    $one = app(ImportWorkflow::class)->profile($actor, $batch, 'Synthetic Source');
    $two = app(ImportWorkflow::class)->profile($actor, $batch, 'Synthetic Source');
    expect($one->version)->toBe(1)->and($two->version)->toBe(2)->and($two->mapping_definition)->toBe($one->mapping_definition);
    expect(fn () => DB::transaction(fn () => DB::table('import_mapping_profiles')->where('id', $one->id)->update(['name' => 'Overwritten'])))->toThrow(QueryException::class);
    app(ImportWorkflow::class)->map($actor, $batch, 'Intake', 1, $two->id);
    expect($batch->mappings()->where('match_method', 'profile')->count())->toBe(5);
});

test('unresolved required and duplicate target mappings are rejected before row writes', function (): void {
    $actor = staffManager();
    $batch = stagedImport($actor, [['customer_reference', 'party_type', 'name', 'status', 'name'], ['A1', 'person', 'Synthetic Person', 'active', 'Second Name']]);
    $selections = mappedSelections($batch);
    $last = $batch->mappings()->latest('source_column_index')->firstOrFail();
    expect($last->field_id)->toBeNull();
    $selections[$last->id]['field_id'] = (string) ImportField::query()->where('module', 'customer')->where('canonical_field', 'name')->firstOrFail()->id;
    expect(fn () => app(ImportWorkflow::class)->preview($actor, $batch, $selections))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('debtors', 0);
});

test('alias and canonical conflicts require review rather than selecting a winner', function (): void {
    $actor = staffManager();
    $alias = new ColumnAlias;
    $alias->forceFill(['module' => 'customer', 'field_id' => ImportField::query()->where('module', 'customer')->where('canonical_field', 'name')->value('id'), 'alias' => 'Customer Reference', 'normalized_alias' => 'customer reference'])->save();
    $batch = stagedImport($actor, [['Customer Reference', 'party_type', 'name', 'status'], ['A1', 'person', 'Synthetic Person', 'active']]);
    $mapping = $batch->mappings()->orderBy('source_column_index')->firstOrFail();
    expect($mapping->field_id)->toBeNull()->and($mapping->matched_rule_snapshot['ambiguous'])->toBeTrue();
});

test('scanner rejection and scanner unavailability fail closed without parsing or staging', function (string $failure): void {
    $actor = staffManager();
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('scan')->once()->andThrow(new RuntimeException($failure));
    app()->instance(MalwareScanner::class, $scanner);
    $file = importFile([['name'], ['Synthetic']]);
    try {
        $batch = app(ImportWorkflow::class)->upload($actor, ['kind' => 'customer', 'template_id' => ImportTemplate::query()->where('kind', 'customer')->value('id')], $file);
    } finally {
        @unlink($file->getRealPath());
    }
    app(ImportWorkflow::class)->scan($batch->id);
    expect($batch->refresh()->status)->toBe('failed')->and($batch->source->scan_status)->toBe($failure === 'malware_detected' ? 'rejected' : 'error');
    expect($batch->rows()->count())->toBe(0);
    $this->assertDatabaseCount('debtors', 0);
})->with(['malware_detected', 'scanner_unavailable']);

test('source tampering is detected and formulas are never evaluated', function (): void {
    $actor = staffManager();
    $batch = stagedImport($actor, [['customer_reference', 'party_type', 'name', 'status'], ['000123', 'person', 'Synthetic Person', 'active']]);
    Storage::disk('s3')->put($batch->source->object_key, Crypt::encryptString('tampered'));
    expect(fn () => app(ImportWorkflow::class)->map($actor, $batch, 'Intake', 1))->toThrow(RuntimeException::class);
    $file = importFile([['name'], ['=1+1']]);
    try {
        expect(fn () => app(WorkbookReader::class)->read($file->getRealPath(), 'Intake', 1))->toThrow(RuntimeException::class, 'formulas_not_allowed');
    } finally {
        @unlink($file->getRealPath());
    }
});

test('XLS files and bounded workbook limits use the validated parser', function (): void {
    $actor = staffManager();
    $batch = stagedImport($actor, [['customer_reference', 'party_type', 'name', 'status'], ['000007', 'person', 'Synthetic Person', 'active']], 'customer', 'xls');
    expect($batch->rows()->firstOrFail()->raw_payload_ciphertext[1])->toBe('000007');
    config(['imports.max_rows' => 0]);
    $file = importFile([['name'], ['Synthetic']]);
    try {
        expect(fn () => app(WorkbookReader::class)->read($file->getRealPath(), 'Intake', 1))->toThrow(RuntimeException::class);
    } finally {
        @unlink($file->getRealPath());
    }
});

test('permission revocation is checked again during queued execution', function (): void {
    $actor = staffManager();
    $batch = readyImport($actor, [['customer_reference', 'party_type', 'name', 'status'], ['000123', 'person', 'Synthetic Person', 'active']]);
    app(ImportWorkflow::class)->confirm($actor, $batch, $batch->mapping_digest);
    $actor->syncRoles([]);
    app(ImportWorkflow::class)->process($batch->id);
    expect($batch->refresh()->status)->toBe('failed')->and($batch->failed_count)->toBe(1);
    $this->assertDatabaseCount('debtors', 0);
    $this->actingAs($actor)->get(route('admin.imports.review', $batch))->assertForbidden();
});

test('read only and rider access cannot map or confirm', function (): void {
    $actor = staffManager();
    $batch = readyImport($actor, [['customer_reference', 'party_type', 'name', 'status'], ['000123', 'person', 'Synthetic Person', 'active']]);
    $actor->removeRole('Admin')->givePermissionTo(['admin.access', 'imports.view']);
    Livewire::actingAs($actor)->test(Review::class, ['import' => $batch])->call('confirm')->assertForbidden();
    $rider = User::factory()->create();
    $rider->assignRole('Rider');
    $this->actingAs($rider)->get(route('admin.imports.index'))->assertForbidden();
});

test('exact preview confirmation and immutable payload prevent silent mapping changes', function (): void {
    $actor = staffManager();
    $batch = readyImport($actor, [['customer_reference', 'party_type', 'name', 'status'], ['000123', 'person', 'Synthetic Person', 'active']]);
    expect(fn () => app(ImportWorkflow::class)->confirm($actor, $batch, str_repeat('0', 64)))->toThrow(ValidationException::class);
    app(ImportWorkflow::class)->confirm($actor, $batch, $batch->mapping_digest);
    $row = $batch->rows()->firstOrFail();
    expect(fn () => DB::transaction(fn () => DB::table('import_rows')->where('id', $row->id)->update(['raw_payload_ciphertext' => 'changed'])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('import_column_mappings')->where('import_batch_id', $batch->id)->delete()))->toThrow(QueryException::class);
    expect(fn () => app(ImportWorkflow::class)->map($actor, $batch, 'Intake', 1))->toThrow(ValidationException::class);
});

test('outbox queue publication is durable and worker replay records one checkpoint', function (): void {
    $actor = staffManager();
    $file = importFile([['customer_reference', 'party_type', 'name', 'status'], ['A1', 'person', 'Synthetic Person', 'active']]);
    try {
        $batch = app(ImportWorkflow::class)->upload($actor, ['kind' => 'customer', 'template_id' => ImportTemplate::query()->where('kind', 'customer')->value('id')], $file);
    } finally {
        @unlink($file->getRealPath());
    }
    Queue::fake();
    $this->artisan('imports:dispatch')->assertSuccessful();
    Queue::assertPushed(ProcessImport::class, 1);
    $event = OutboxEvent::query()->where('aggregate_id', $batch->id)->firstOrFail();
    expect($event->dispatched_at)->not->toBeNull();
    (new ProcessImport($event->id))->handle(app(ImportWorkflow::class));
    (new ProcessImport($event->id))->handle(app(ImportWorkflow::class));
    expect(ProcessedEvent::query()->where('event_key', $event->event_key)->count())->toBe(1)->and($batch->refresh()->status)->toBe('mapping');
});

test('cancellation reconciles outstanding rows and redacted errors never expose sensitive source data', function (): void {
    $actor = staffManager();
    $batch = readyImport($actor, [['customer_reference', 'party_type', 'name', 'status'], ['000123', 'person', 'Sensitive Person', 'active']]);
    app(ImportWorkflow::class)->confirm($actor, $batch, $batch->mapping_digest);
    app(ImportWorkflow::class)->cancel($actor, $batch);
    app(ImportWorkflow::class)->process($batch->id);
    expect($batch->refresh()->status)->toBe('cancelled')->and($batch->failed_count)->toBe(1);
    Livewire::actingAs($actor)->test(Review::class, ['import' => $batch])->call('downloadErrors')->assertFileDownloaded('import-errors-'.$batch->public_id.'.csv');
    $this->assertDatabaseCount('debtors', 0);
    expect(AuditLog::query()->where('module', 'M04')->get()->toJson())->not->toContain('Sensitive Person', '000123');
});

test('import register and review render without exposing raw staged payloads', function (): void {
    $actor = staffManager();
    $batch = readyImport($actor, [['customer_reference', 'party_type', 'name', 'status'], ['000123', 'person', 'Sensitive Person', 'active']]);
    $this->actingAs($actor)->get(route('admin.imports.index'))->assertOk()->assertSee('Excel imports');
    Livewire::actingAs($actor)->test(Review::class, ['import' => $batch])->set('tab', 'rows')->assertDontSee('000123')->assertDontSee('Sensitive Person');
});

test('registry aliases are published and publication needs the separate approval permission', function (): void {
    $actor = staffManager();
    $nameField = ImportField::query()->where('module', 'customer')->where('canonical_field', 'name')->firstOrFail();
    Livewire::actingAs($actor)->test(Registry::class)->set('field', (string) $nameField->id)->set('alias', 'Customer Name')->call('addAlias')->assertHasNoErrors();
    expect(ColumnAlias::query()->firstOrFail()->normalized_alias)->toBe('customer name');
    $template = ImportTemplate::query()->where('kind', 'customer')->firstOrFail();
    Livewire::actingAs($actor)->test(Registry::class)->call('newTemplateVersion', $template->public_id)->assertHasNoErrors();
    $draft = ImportTemplate::query()->where('code', $template->code)->where('version', 2)->firstOrFail();
    expect($draft->is_active)->toBeFalse();
    Livewire::actingAs($actor)->test(Registry::class)->call('toggleTemplate', $draft->public_id)->assertForbidden();
    $actor->givePermissionTo('templates.approve');
    Livewire::actingAs($actor)->test(Registry::class)->call('toggleTemplate', $draft->public_id)->assertHasNoErrors();
    expect($draft->refresh()->is_active)->toBeTrue();
});

test('duplicate upload returns the existing review and failed frozen imports permit a separate fresh review', function (): void {
    $actor = staffManager();
    $file = importFile([['customer_reference', 'party_type', 'name', 'status'], ['A1', 'person', 'Synthetic Person', 'active']]);
    $input = ['kind' => 'customer', 'template_id' => ImportTemplate::query()->where('kind', 'customer')->value('id')];
    try {
        $one = app(ImportWorkflow::class)->upload($actor, $input, $file);
        $two = app(ImportWorkflow::class)->upload($actor, $input, $file);
        expect($two->id)->toBe($one->id);
        $this->assertDatabaseCount('documents', 1);
        app(ImportWorkflow::class)->scan($one->id);
        app(ImportWorkflow::class)->map($actor, $one, 'Intake', 1);
        app(ImportWorkflow::class)->preview($actor, $one, mappedSelections($one));
        app(ImportWorkflow::class)->confirm($actor, $one, $one->refresh()->mapping_digest);
        app(ImportWorkflow::class)->interrupted($one->id);
        $fresh = app(ImportWorkflow::class)->upload($actor, $input, $file);
        expect($fresh->id)->not->toBe($one->id)->and($fresh->status)->toBe('uploaded')->and($one->refresh()->confirmed_at)->not->toBeNull();
    } finally {
        @unlink($file->getRealPath());
    }
});

test('database blocks mismatched import target links and immutable document source mutation', function (): void {
    $actor = staffManager();
    $batch = stagedImport($actor, [['customer_reference', 'party_type', 'name', 'status'], ['A1', 'person', 'Synthetic Person', 'active']]);
    expect(fn () => DB::transaction(fn () => DB::table('documents')->where('id', $batch->source_document_id)->update(['sha256' => str_repeat('0', 64)])))->toThrow(QueryException::class);
    $parent = AssignmentBatch::factory()->create();
    expect(fn () => DB::transaction(fn () => DB::table('import_rows')->where('import_batch_id', $batch->id)->update(['target_assignment_batch_id' => $parent->id])))->toThrow(QueryException::class);
});

test('unsafe archive external relationships are rejected before workbook parsing', function (): void {
    $file = importFile([['name'], ['Synthetic']]);
    try {
        $zip = new ZipArchive;
        $zip->open($file->getRealPath());
        $zip->addFromString('xl/externalLinks/_rels/externalLink1.xml.rels', '<Relationship TargetMode="External" Target="https://example.test"/>');
        $zip->close();
        expect(fn () => app(WorkbookReader::class)->inspect($file->getRealPath()))->toThrow(RuntimeException::class);
    } finally {
        @unlink($file->getRealPath());
    }
});

test('archived parent batches block import retries until explicitly reopened', function (): void {
    $actor = staffManager();
    $parent = AssignmentBatch::factory()->create();
    $job = ImportBatch::factory()->create(['assignment_batch_id' => $parent->id, 'status' => 'failed']);
    $parent->status = BatchStatus::Archived;
    $parent->save();
    expect(fn () => app(ImportWorkflow::class)->retry($actor, $job))->toThrow(ValidationException::class);
    expect($job->refresh()->status)->toBe('failed');
});

test('queue publication failures keep the outbox pending and return an actionable command failure', function (): void {
    ImportBatch::factory()->create();
    $event = new OutboxEvent;
    $event->forceFill(['event_key' => (string) Str::uuid7(), 'event_type' => 'import.scan', 'aggregate_type' => 'import', 'aggregate_id' => ImportBatch::query()->value('id'), 'payload' => ['phase' => 'scan'], 'available_at' => now()])->save();
    $manager = Mockery::mock(QueueManager::class);
    $manager->shouldReceive('connection')->andThrow(new RuntimeException('Synthetic queue failure'));
    app()->instance('queue', $manager);
    $this->artisan('imports:dispatch')->assertFailed();
    expect($event->refresh()->dispatched_at)->toBeNull()->and($event->attempts)->toBe(1)->and($event->last_error)->not->toContain('Synthetic');
});
