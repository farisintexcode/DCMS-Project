<?php

use App\Actions\Assignments\ChangeBatchStatus;
use App\Actions\Assignments\SaveAssignmentBatch;
use App\Enums\BatchStatus;
use App\Livewire\Admin\Assignments\Editor;
use App\Livewire\Admin\Assignments\Index;
use App\Models\AssignmentBatch;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\DebtAccount;
use App\Models\ImportBatch;
use App\Models\ReferenceValue;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(LazilyRefreshDatabase::class);
beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(DatabaseSeeder::class);
});

function batchInput(Client $client, array $overrides = []): array
{
    return array_replace(['client_id' => $client->id, 'name' => 'October Recovery', 'external_reference' => '000123', 'type_code' => 'domestic_dr', 'start_date' => '2026-10-01', 'due_date' => '2026-10-31', 'description' => 'Synthetic operations notes', 'dca_code' => '  001  ', 'dca_name' => '  Synthetic Agency  ', 'la_name' => '', 'state_code' => 'selangor'], $overrides);
}

test('assignment creation preserves identifiers trims metadata and audits without trusting control fields', function (): void {
    [$user, $client] = customerOperator();
    Livewire::actingAs($user)->test(Editor::class)->set('data', batchInput($client, ['created_by' => 9999, 'status' => 'archived', 'lock_version' => 100, 'type_category' => 'position']))->call('save')->assertHasNoErrors()->assertRedirect();
    $batch = AssignmentBatch::query()->firstOrFail();
    expect($batch->external_reference)->toBe('000123')->and($batch->dca_code)->toBe('001')->and($batch->dca_name)->toBe('Synthetic Agency')
        ->and($batch->created_by)->toBe($user->id)->and($batch->status)->toBe(BatchStatus::Active)->and($batch->lock_version)->toBe(0)
        ->and($batch->type_category)->toBe('assignment_type')->and($batch->client->id)->toBe($client->id);
    $audit = AuditLog::query()->where('module', 'M03')->firstOrFail();
    expect($audit->action)->toBe('assignment_created')->and($audit->toJson())->not->toContain('Synthetic operations notes', 'Synthetic Agency', 'October Recovery');
});

test('batch validation refuses invalid metadata atomically', function (array $changes, string $field): void {
    $audits = AuditLog::query()->count();
    [$user, $client] = customerOperator();
    Livewire::actingAs($user)->test(Editor::class)->set('data', batchInput($client, $changes))->call('save')->assertHasErrors('data.'.$field);
    $this->assertDatabaseCount('assignment_batches', 0);
    $this->assertDatabaseCount('audit_logs', $audits);
})->with([
    [['name' => ' '], 'name'], [['due_date' => '2026-09-30'], 'due_date'], [['start_date' => '2026-02-30'], 'start_date'],
    [['type_code' => '99'], 'type_code'], [['state_code' => 'unknown'], 'state_code'], [['external_reference' => str_repeat('x', 101)], 'external_reference'],
    [['dca_code' => ['bad']], 'dca_code'], [['description' => str_repeat('x', 5001)], 'description'],
]);

test('the new assignment form follows the operational field scheme and fills the single client and assignment date', function (): void {
    $this->travelTo('2026-11-01 04:00:00');
    [$user, $client] = customerOperator();

    Livewire::actingAs($user)->test(Editor::class)
        ->assertSet('data.client_id', (string) $client->id)->assertSet('data.start_date', '2026-11-01')->assertSet('status', 'active')
        ->assertDontSee('Commissioning client')->assertDontSee('External batch reference')
        ->assertSeeInOrder(['Assignment type', 'Assignment name', 'Assignment due date', 'Description (optional)', 'DCA code', 'DCA name', 'LA name', 'State', 'Status'])
        ->assertSeeInOrder(['Vacant', 'Domestic DR', 'Domestic CTOS', 'Domestic LOD', 'Domestic LOD CTOS', 'COM DR', 'COM LOD']);
});

test('a new assignment can start archived but saving an existing one never changes its status', function (): void {
    [$user, $client] = customerOperator();
    Livewire::actingAs($user)->test(Editor::class)->set('data', batchInput($client))->set('status', 'archived')->call('save')->assertHasNoErrors();
    $archived = AssignmentBatch::query()->firstOrFail();
    expect($archived->status)->toBe(BatchStatus::Archived)->and($archived->archived_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'assignment_created')->firstOrFail()->toJson())->toContain('archived');

    $active = app(SaveAssignmentBatch::class)->handle($user, batchInput($client, ['external_reference' => 'B-2']));
    app(SaveAssignmentBatch::class)->handle($user, batchInput($client, ['external_reference' => 'B-2', 'name' => 'Renamed']), $active, 0, 'archived');
    expect($active->fresh()->status)->toBe(BatchStatus::Active)->and($active->fresh()->name)->toBe('Renamed');
    Livewire::actingAs($user)->test(Editor::class)->set('data', batchInput($client, ['external_reference' => 'B-3']))->set('status', 'deleted')->call('save')->assertHasErrors('status');
});

test('external references are unique within the client including archives while null references can repeat', function (): void {
    [$user, $client] = customerOperator();
    $save = app(SaveAssignmentBatch::class);
    $first = $save->handle($user, batchInput($client));
    app(ChangeBatchStatus::class)->handle($user, $first, 'archived', 'Synthetic completed work package', 0);
    expect(fn () => $save->handle($user, batchInput($client)))->toThrow(ValidationException::class);
    $save->handle($user, batchInput($client, ['external_reference' => '']));
    $save->handle($user, batchInput($client, ['external_reference' => '']));
    [$otherUser, $otherClient] = customerOperator();
    $save->handle($otherUser, batchInput($otherClient));
    $this->assertDatabaseCount('assignment_batches', 4);
});

test('client scope isolates register direct routes and direct actions', function (): void {
    [$user, $client] = customerOperator();
    $own = AssignmentBatch::factory()->for($client)->create(['name' => 'Visible own batch']);
    $foreign = AssignmentBatch::factory()->create(['name' => 'Private foreign batch']);
    $this->actingAs($user)->get(route('admin.assignments.index'))->assertOk()->assertSee('Visible own batch')->assertDontSee('Private foreign batch');
    $this->get(route('admin.assignments.edit', $foreign))->assertNotFound();
    expect(fn () => app(SaveAssignmentBatch::class)->handle($user, batchInput($foreign->client), $foreign, 0))->toThrow(HttpException::class);
    expect(fn () => app(ChangeBatchStatus::class)->handle($user, $foreign, 'archived', 'Invalid scope', 0))->toThrow(HttpException::class);
    $this->get('/admin/assignments/not-a-uuid/edit')->assertNotFound();
});

test('view only staff cannot create edit or archive and Rider cannot enter the register', function (): void {
    [$user, $client] = customerOperator();
    $batch = AssignmentBatch::factory()->for($client)->create();
    $user->removeRole('Admin')->givePermissionTo(['admin.access', 'assignments.view']);
    $this->actingAs($user)->get(route('admin.assignments.edit', $batch))->assertOk()->assertSee('view-only access');
    expect(fn () => app(SaveAssignmentBatch::class)->handle($user, batchInput($client), $batch, 0))->toThrow(AuthorizationException::class);
    expect(fn () => app(ChangeBatchStatus::class)->handle($user, $batch, 'archived', 'Forbidden write', 0))->toThrow(AuthorizationException::class);
    $this->get(route('admin.assignments.create'))->assertForbidden();
    $rider = User::factory()->create()->assignRole('Rider');
    $this->actingAs($rider)->get(route('admin.assignments.index'))->assertForbidden();
});

test('stale edits and status changes cannot overwrite a newer batch revision', function (): void {
    [$user, $client] = customerOperator();
    $save = app(SaveAssignmentBatch::class);
    $batch = $save->handle($user, batchInput($client));
    $save->handle($user, batchInput($client, ['name' => 'Updated batch']), $batch, 0);
    expect(fn () => $save->handle($user, batchInput($client), $batch, 0))->toThrow(ValidationException::class);
    expect(fn () => app(ChangeBatchStatus::class)->handle($user, $batch, 'archived', 'Stale attempt', 0))->toThrow(ValidationException::class);
    expect($batch->fresh()->name)->toBe('Updated batch')->and($batch->fresh()->lock_version)->toBe(1);
});

test('archive is read only reopening requires a separate grant and reason and keeps audit', function (): void {
    [$user, $client] = customerOperator();
    $save = app(SaveAssignmentBatch::class);
    $change = app(ChangeBatchStatus::class);
    $batch = $save->handle($user, batchInput($client));
    expect(fn () => $change->handle($user, $batch, 'archived', ' ', 0))->toThrow(ValidationException::class);
    $batch = $change->handle($user, $batch, 'archived', 'Work package finished', 0);
    expect($batch->archived_at)->not->toBeNull()->and($batch->lock_version)->toBe(1);
    expect(fn () => $save->handle($user, batchInput($client, ['name' => 'Tampered archive']), $batch, 1))->toThrow(ValidationException::class);
    expect(fn () => $change->handle($user, $batch, 'active', 'Reopen review', 1))->toThrow(AuthorizationException::class);
    $user->givePermissionTo('assignments.reopen');
    $batch = $change->handle($user, $batch, 'active', 'Approved reopening for new work', 1);
    expect($batch->status)->toBe(BatchStatus::Active)->and($batch->archived_at)->toBeNull()->and($batch->lock_version)->toBe(2);
    expect(AuditLog::query()->where('module', 'M03')->orderBy('id')->pluck('action')->all())->toBe(['assignment_created', 'assignment_archived', 'assignment_reopened']);
});

test('permissions and scope are rechecked after the editor has been opened', function (): void {
    [$user, $client] = customerOperator();
    $batch = AssignmentBatch::factory()->for($client)->create();
    $form = Livewire::actingAs($user)->test(Editor::class, ['batch' => $batch]);
    $user->clientScopes()->update(['valid_from' => now()->subDay(), 'valid_to' => now()->subSecond()]);
    $form->call('save')->assertNotFound();
    expect($batch->fresh()->lock_version)->toBe(0);
});

test('inactive historic lookup codes can be retained but cannot be newly assigned', function (): void {
    [$user, $client] = customerOperator();
    $save = app(SaveAssignmentBatch::class);
    $batch = $save->handle($user, batchInput($client));
    ReferenceValue::query()->where('category', 'assignment_type')->where('code', 'domestic_dr')->update(['is_active' => false]);
    $save->handle($user, batchInput($client, ['name' => 'Retained type']), $batch, 0);
    expect(fn () => $save->handle($user, batchInput($client, ['external_reference' => 'NEW'])))->toThrow(ValidationException::class);
});

test('literal search safe filters and archive register do not leak other records', function (): void {
    [$user, $client] = customerOperator();
    $batch = AssignmentBatch::factory()->for($client)->create(['name' => 'Percent 100% package']);
    AssignmentBatch::factory()->for($client)->create(['name' => 'Other package', 'status' => 'archived', 'archived_at' => now()]);
    Livewire::actingAs($user)->test(Index::class)->set('query', '%')->assertSee('Percent 100% package')->assertDontSee('Other package')
        ->set('client', 'not-an-id')->assertDontSee('Percent 100% package')->set('client', '')->set('from', 'invalid')->assertSee('format');
    Livewire::actingAs($user)->test(Index::class)->set('query', '')->set('status', 'archived')->set('sort', 'name; drop table users')->assertSee('Other package')->assertDontSee('Percent 100% package');
});

test('batch client is immutable and PostgreSQL enforces dates references and lookup categories', function (): void {
    [$user, $client] = customerOperator();
    $batch = AssignmentBatch::factory()->for($client)->create();
    $foreign = Client::factory()->create();
    expect(fn () => app(SaveAssignmentBatch::class)->handle($user, batchInput($foreign), $batch, 0))->toThrow(ValidationException::class);
    foreach (['client_id' => $foreign->id, 'due_date' => '2026-09-01', 'type_category' => 'position', 'status' => 'deleted'] as $column => $value) {
        expect(fn () => DB::transaction(fn () => DB::table('assignment_batches')->where('id', $batch->id)->update([$column => $value])))->toThrow(QueryException::class);
    }
    expect(fn () => DB::transaction(fn () => DB::table('assignment_batches')->insert(array_merge($batch->getRawOriginal(), ['id' => 999999, 'public_id' => (string) Str::uuid7()]))))->toThrow(QueryException::class);
});

test('archive checks canonical task review and import states before preserving terminal history', function (): void {
    [$user, $client] = customerOperator();
    $batch = AssignmentBatch::factory()->for($client)->create();
    Task::factory()->create(['assignment_batch_id' => $batch->id, 'debt_account_id' => DebtAccount::factory()->create(['client_id' => $client->id])->id, 'work_status' => 'submitted', 'review_status' => 'pending']);
    $change = app(ChangeBatchStatus::class);
    expect(fn () => $change->handle($user, $batch, 'archived', 'Attempt with outstanding work', 0))->toThrow(ValidationException::class);
    expect(fn () => DB::transaction(fn () => DB::table('tasks')->update(['work_status' => 'completed', 'review_status' => 'pending'])))->toThrow(QueryException::class);
    expect(fn () => $change->handle($user, $batch, 'archived', 'Invalid completion projection', 0))->toThrow(ValidationException::class);
    DB::table('tasks')->update(['work_status' => 'completed', 'review_status' => 'approved']);
    ImportBatch::factory()->create(['assignment_batch_id' => $batch->id, 'client_id' => $client->id, 'status' => 'mapping']);
    expect(fn () => $change->handle($user, $batch, 'archived', 'Pending import', 0))->toThrow(ValidationException::class);
    expect(fn () => app(SaveAssignmentBatch::class)->handle($user, batchInput($client, ['type_code' => 'vacant']), $batch, 0))->toThrow(ValidationException::class);
    DB::table('import_batches')->update(['status' => 'failed', 'accepted_count' => 2, 'processed_count' => 1]);
    expect(fn () => $change->handle($user, $batch, 'archived', 'Unreconciled import rows', 0))->toThrow(ValidationException::class);
    DB::table('import_batches')->update(['failed_count' => 1]);
    $change->handle($user, $batch, 'archived', 'All work disposed', 0);
    expect(DB::table('tasks')->count())->toBe(1)->and(DB::table('import_batches')->count())->toBe(1);
});

test('assignment write and audit roll back together if audit persistence fails', function (): void {
    [$user, $client] = customerOperator();
    DB::unprepared("CREATE FUNCTION reject_batch_audit() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Synthetic audit failure'; END; $$; CREATE TRIGGER reject_batch_audit BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION reject_batch_audit();");
    expect(fn () => app(SaveAssignmentBatch::class)->handle($user, batchInput($client)))->toThrow(QueryException::class);
    $this->assertDatabaseCount('assignment_batches', 0);
});
