<?php

use App\Actions\Customers\SaveClient;
use App\Livewire\Admin\Assignments\Editor as AssignmentEditor;
use App\Livewire\Admin\Customers\Clients;
use App\Models\AuditLog;
use App\Models\Client;
use App\Services\Imports\MalwareScanner;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(LazilyRefreshDatabase::class);
beforeEach(function (): void {
    $this->withoutVite();
    config(['dcms.search_key' => base64_encode(str_repeat('t', 32))]);
    $this->seed(DatabaseSeeder::class);
});

test('an admin with scopes.manage creates a client and immediately gets access to it', function (): void {
    $user = staffManager();
    $user->givePermissionTo('scopes.manage');

    Livewire::actingAs($user)->test(Clients::class)
        ->set('data', ['code' => ' TNB-01 ', 'name' => ' Tenaga Synthetic '])
        ->call('save')->assertHasNoErrors()->assertSee('Client Tenaga Synthetic created.');

    $client = Client::query()->where('code', 'TNB-01')->firstOrFail();
    expect($client->name)->toBe('Tenaga Synthetic');
    expect($user->hasClientScope($client))->toBeTrue();
    expect(AuditLog::query()->where('action', 'client_created')->where('entity_type', 'client')->exists())->toBeTrue();
    expect(AuditLog::query()->where('action', 'scope_granted')->exists())->toBeTrue();
});

test('a client category is optional, reuses an existing spelling and is offered as a suggestion', function (): void {
    $user = staffManager();
    $user->givePermissionTo('scopes.manage');
    Client::factory()->create(['code' => 'OLD', 'category' => 'Utility']);

    Livewire::actingAs($user)->test(Clients::class)->assertSeeHtml('<option value="Utility"></option>')
        ->set('data', ['code' => 'TNB', 'name' => 'Tenaga Synthetic', 'category' => '  utility '])->call('save')->assertHasNoErrors()
        ->set('data', ['code' => 'MAXIS', 'name' => 'Telco Synthetic', 'category' => ''])->call('save')->assertHasNoErrors();

    expect(Client::query()->where('code', 'TNB')->value('category'))->toBe('Utility');
    expect(Client::query()->where('code', 'MAXIS')->value('category'))->toBeNull();
});

test('the new client is offered when creating an assignment', function (): void {
    $user = staffManager();
    $user->givePermissionTo('scopes.manage');

    Livewire::actingAs($user)->test(AssignmentEditor::class)->assertSee('Create a client first');
    Livewire::actingAs($user)->test(Clients::class)->set('data', ['code' => 'ACME', 'name' => 'Acme Synthetic'])->call('save');

    Livewire::actingAs($user)->test(AssignmentEditor::class)->assertDontSee('Create a client first')
        ->assertSet('data.client_id', (string) Client::query()->where('code', 'ACME')->value('id'));
});

test('client codes are unique and validated', function (array $data, string $field): void {
    $user = staffManager();
    $user->givePermissionTo('scopes.manage');
    Client::factory()->create(['code' => 'TAKEN']);

    Livewire::actingAs($user)->test(Clients::class)->set('data', array_replace(['code' => 'NEW', 'name' => 'Synthetic'], $data))
        ->call('save')->assertHasErrors('data.'.$field);

    $this->assertDatabaseCount('clients', 1);
})->with([
    [['code' => 'TAKEN'], 'code'],
    [['code' => 'has space'], 'code'],
    [['name' => ' '], 'name'],
]);

function clientWorkbookFile(array $rows): UploadedFile
{
    $book = new Spreadsheet;
    $book->getActiveSheet()->fromArray($rows);
    $path = tempnam(sys_get_temp_dir(), 'client-fixture-');
    (new Xlsx($book))->save($path);
    $book->disconnectWorksheets();

    return new UploadedFile($path, 'clients.xlsx', null, null, true);
}

function scannedClean(): void
{
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('scan')->andReturnNull();
    app()->instance(MalwareScanner::class, $scanner);
}

test('clients upload from a workbook after preview, with loose headers and extra columns ignored', function (): void {
    scannedClean();
    $user = staffManager();
    $user->givePermissionTo('scopes.manage');
    $file = clientWorkbookFile([['Kod Klien', 'Client Name', 'Kategori', 'Notes'], ['TNB', 'Tenaga Synthetic', 'Utility', 'ignored'], [null, null, null, null], ['SYABAS', 'Air Synthetic', null, null]]);

    $component = Livewire::actingAs($user)->test(Clients::class)->set('mode', 'upload')->set('workbook', $file)->call('checkWorkbook')
        ->assertHasNoErrors()->assertSet('uploadErrors', [])->assertSee('Air Synthetic');
    $this->assertDatabaseCount('clients', 0);

    $component->call('importWorkbook')->assertHasNoErrors()->assertSee('2 clients created from the workbook.');

    expect(Client::query()->orderBy('code')->pluck('name', 'code')->all())->toBe(['SYABAS' => 'Air Synthetic', 'TNB' => 'Tenaga Synthetic']);
    expect(Client::query()->orderBy('code')->pluck('category', 'code')->all())->toBe(['SYABAS' => null, 'TNB' => 'Utility']);
    expect($user->hasClientScope(Client::query()->where('code', 'SYABAS')->firstOrFail()))->toBeTrue();
    expect(AuditLog::query()->where('action', 'client_created')->count())->toBe(2);
});

test('a workbook with any bad row creates nothing and lists every problem by row', function (): void {
    scannedClean();
    $user = staffManager();
    $user->givePermissionTo('scopes.manage');
    Client::factory()->create(['code' => 'TAKEN']);
    $file = clientWorkbookFile([['Client code', 'Client name'], ['GOOD', 'Fine Synthetic'], ['TAKEN', 'Duplicate of existing'], ['GOOD', 'Repeated in file'], ['NONAME', null]]);

    $component = Livewire::actingAs($user)->test(Clients::class)->set('workbook', $file)->call('checkWorkbook')->assertDontSee('Create 4 clients');
    expect($component->get('uploadErrors'))->toHaveCount(3)
        ->and(implode(' ', $component->get('uploadErrors')))->toContain('Row 3:')->toContain('Row 4:')->toContain('Row 5:');

    expect(fn () => app(SaveClient::class)->handleMany($user, $component->get('uploadRows')))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('clients', 1);
});

test('a workbook without the required headers is refused', function (): void {
    scannedClean();
    $user = staffManager();
    $user->givePermissionTo('scopes.manage');

    Livewire::actingAs($user)->test(Clients::class)->set('workbook', clientWorkbookFile([['Company', 'Region'], ['TNB', 'Central']]))->call('checkWorkbook')
        ->assertHasErrors('workbook')->assertSet('uploadRows', []);
});

test('an unavailable malware scanner keeps the workbook closed', function (): void {
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('scan')->andThrow(new RuntimeException('scanner_unavailable'));
    app()->instance(MalwareScanner::class, $scanner);
    $user = staffManager();
    $user->givePermissionTo('scopes.manage');

    Livewire::actingAs($user)->test(Clients::class)->set('workbook', clientWorkbookFile([['Client code', 'Client name'], ['TNB', 'Tenaga Synthetic']]))->call('checkWorkbook')
        ->assertHasErrors('workbook')->assertSet('uploadRows', []);
});

test('the client template downloads as a workbook', function (): void {
    $user = staffManager();
    $user->givePermissionTo('scopes.manage');

    Livewire::actingAs($user)->test(Clients::class)->call('downloadTemplate')->assertFileDownloaded('client-upload-template.xlsx');
});

test('admins without scopes.manage cannot open the page or create clients', function (): void {
    $user = staffManager();

    $this->actingAs($user)->get(route('admin.customers.clients'))->assertForbidden();
    Livewire::actingAs($user)->test(Clients::class)->assertForbidden();

    $this->assertDatabaseCount('clients', 0);
    expect(fn () => app(SaveClient::class)->handleMany($user, [2 => ['code' => 'TNB', 'name' => 'Tenaga Synthetic']]))->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('clients', 0);
});
