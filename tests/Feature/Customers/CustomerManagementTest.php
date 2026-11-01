<?php

use App\Actions\Customers\SaveCategory;
use App\Actions\Customers\SaveCustomer;
use App\Livewire\Admin\Assignments\Editor as AssignmentEditor;
use App\Livewire\Admin\Customers\Categories;
use App\Livewire\Admin\Customers\Editor;
use App\Livewire\Admin\Customers\Index;
use App\Livewire\Admin\Customers\Show;
use App\Models\AuditLog;
use App\Models\CustomerCategory;
use App\Models\Debtor;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);
beforeEach(function (): void {
    $this->withoutVite();
    config(['dcms.search_key' => base64_encode(str_repeat('t', 32))]);
    $this->seed(DatabaseSeeder::class);
});

test('customer pages open without choosing any portfolio first', function (string $component): void {
    Livewire::actingAs(staffManager())->test($component)->assertOk()->assertDontSee('Commissioning client');
})->with([
    'customers' => [Index::class],
    'customer editor' => [Editor::class],
    'categories' => [Categories::class],
    'assignments' => [AssignmentEditor::class],
]);

test('customer addresses without a valid reference are not found', function (): void {
    $this->actingAs(staffManager())->get('/admin/customers/not-a-uuid')->assertNotFound();
});

test('customer creation persists encrypted identity contacts address and memberships atomically', function (): void {
    $user = staffManager();
    $category = CustomerCategory::factory()->create();
    $input = customerInput(['identity' => '001122-33-4455', 'contacts' => [['kind' => 'email', 'value' => 'nur@example.test', 'is_primary' => true]], 'addresses' => [['kind' => 'billing', 'recipient' => 'Nur', 'lines' => "10 Synthetic Road\nTest Town", 'postcode' => '00100', 'state_code' => 'selangor']], 'category_ids' => [$category->id]]);

    Livewire::actingAs($user)->test(Editor::class)->set('data', $input)->call('save')->assertHasNoErrors()->assertRedirect();

    $customer = Debtor::query()->firstOrFail();
    expect($customer->identity_ciphertext)->toBe('001122-33-4455');
    expect($customer->getRawOriginal('identity_ciphertext'))->not->toContain('001122');
    expect($customer->contacts()->first()->value_ciphertext)->toBe('nur@example.test');
    expect($customer->addresses()->first()->postcode)->toBe('00100');
    $this->assertDatabaseHas('customer_category_assignments', ['customer_id' => $customer->id, 'category_id' => $category->id, 'assigned_by' => $user->id, 'revoked_at' => null]);
    $audit = AuditLog::query()->where('action', 'customer_created')->firstOrFail();
    expect($audit->entity_type)->toBe('debtor');
    expect($audit->module)->toBe('M13');
    expect($audit->toJson())->not->toContain('Nur Aisyah', '001122', 'nur@example.test', 'Synthetic Road');
    expect($customer->toJson())->not->toContain('001122', 'Nur Aisyah');
});

test('updates supersede contacts and addresses while preserving provenance', function (): void {
    $user = staffManager();
    $save = app(SaveCustomer::class);
    $input = customerInput(['identity' => '0123456789', 'contacts' => [['kind' => 'mobile', 'value' => '0123456789', 'is_primary' => true]], 'addresses' => [['kind' => 'billing', 'lines' => 'Original synthetic address']]]);
    $customer = $save->handle($user, $input);
    $oldContact = $customer->contacts()->first();
    $oldAddress = $customer->addresses()->first();
    $input['contacts'][0]['value'] = '0198765432';
    $input['addresses'][0]['lines'] = 'Updated synthetic address';
    $input['identity'] = '';

    $updated = $save->handle($user, $input, $customer, $customer->revision());

    expect($oldContact->fresh()->value_ciphertext)->toBe('0123456789');
    expect($oldContact->fresh()->valid_to)->not->toBeNull();
    expect($oldAddress->fresh()->lines_ciphertext)->toBe('Original synthetic address');
    expect($oldAddress->fresh()->valid_to)->not->toBeNull();
    expect($updated->identity_ciphertext)->toBe('0123456789');
    expect($updated->contacts()->whereNull('valid_to')->sole()->source)->toBe('admin:'.$user->public_id);
    expect($updated->addresses()->whereNull('valid_to')->sole()->lines_ciphertext)->toBe('Updated synthetic address');
});

test('stale edits preserve the latest customer and do not add an audit event', function (): void {
    $user = staffManager();
    $save = app(SaveCustomer::class);
    $customer = $save->handle($user, customerInput());
    $revision = $customer->revision();
    $save->handle($user, customerInput(['name' => 'Latest name']), $customer, $revision);
    $audits = AuditLog::query()->count();

    expect(fn () => $save->handle($user, customerInput(['name' => 'Stale name']), $customer, $revision))->toThrow(ValidationException::class);

    expect($customer->fresh()->display_name_ciphertext)->toBe('Latest name');
    expect(AuditLog::query()->count())->toBe($audits);
});

test('duplicate identity requires review and never merges customers', function (): void {
    $user = staffManager();
    $save = app(SaveCustomer::class);
    $save->handle($user, customerInput(['identity' => 'A-001234']));
    $input = customerInput(['customer_reference' => 'C-002', 'identity' => 'A001234']);

    expect(fn () => $save->handle($user, $input))->toThrow(ValidationException::class);
    expect(Debtor::query()->count())->toBe(1);
    $save->handle($user, [...$input, 'duplicate_confirmed' => true]);

    expect(Debtor::query()->count())->toBe(2);
    expect(AuditLog::query()->where('action', 'customer_created')->latest('id')->first()->new_values_redacted['duplicate_reviewed'])->toBeTrue();
});

test('invalid customer data is reported without partial writes', function (array $changes, string $field): void {
    $audits = AuditLog::query()->count();
    $user = staffManager();

    Livewire::actingAs($user)->test(Editor::class)->set('data', customerInput($changes))->call('save')->assertHasErrors('data.'.$field);

    $this->assertDatabaseCount('debtors', 0);
    $this->assertDatabaseCount('contacts', 0);
    $this->assertDatabaseCount('audit_logs', $audits);
})->with([
    [['name' => ' '], 'name'], [['party_type' => 'unknown'], 'party_type'], [['status' => 'deleted'], 'status'],
    [['customer_reference' => ''], 'customer_reference'], [['identity' => '<script>'], 'identity'],
    [['contacts' => [['kind' => 'email', 'value' => 'bad-email', 'is_primary' => true]]], 'contacts.0.value'],
    [['addresses' => [['kind' => 'billing', 'lines' => 'Address', 'state_code' => 'unknown']]], 'addresses.0.state_code'],
]);

test('customer managers reach every customer and riders cannot enter customer management', function (): void {
    $user = staffManager();
    $customer = Debtor::factory()->create();
    $rider = User::factory()->create();
    $rider->assignRole('Rider');

    $this->actingAs($user)->get(route('admin.customers.show', $customer))->assertOk();
    $this->get(route('admin.customers.edit', $customer))->assertOk();
    $this->actingAs($rider)->get(route('admin.customers.index'))->assertForbidden();
});

test('identifier masking and escaped customer names apply on details and edit', function (): void {
    $user = staffManager();
    $customer = app(SaveCustomer::class)->handle($user, customerInput(['name' => '<script>Unsafe</script>', 'identity' => '001122334455']));

    $this->actingAs($user)->get(route('admin.customers.show', $customer))->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>Unsafe</script>', false)->assertDontSee('001122334455')->assertSee('4455');
    Livewire::actingAs($user)->test(Editor::class, ['customer' => $customer])->assertSet('data.identity', '');
    $user->givePermissionTo('customers.view_sensitive');
    $this->actingAs($user)->get(route('admin.customers.show', $customer))->assertOk()->assertSee('001122334455');
});

test('category deactivation preserves existing membership and blocks new membership', function (): void {
    $user = staffManager();
    $category = CustomerCategory::factory()->create();
    $save = app(SaveCustomer::class);
    $customer = $save->handle($user, customerInput(['category_ids' => [$category->id]]));
    app(SaveCategory::class)->handle($user, ['code' => $category->code, 'name' => $category->name, 'is_active' => false], $category, $category->revision());

    $save->handle($user, customerInput(['category_ids' => [$category->id]]), $customer, $customer->revision());
    expect(fn () => $save->handle($user, customerInput(['customer_reference' => 'C-002', 'category_ids' => [$category->id]])))->toThrow(ValidationException::class);

    expect(Debtor::query()->count())->toBe(1);
    $this->assertDatabaseCount('customer_category_assignments', 1);
});

test('category removal and reassignment append history', function (): void {
    $user = staffManager();
    $category = CustomerCategory::factory()->create();
    $customer = app(SaveCustomer::class)->handle($user, customerInput(['category_ids' => [$category->id]]));

    Livewire::actingAs($user)->test(Show::class, ['customer' => $customer])->set('categoryIds', [])->call('saveCategories')->assertHasNoErrors();
    Livewire::actingAs($user)->test(Show::class, ['customer' => $customer->fresh()])->set('categoryIds', [$category->id])->call('saveCategories')->assertHasNoErrors();

    expect($customer->categoryAssignments()->count())->toBe(2);
    expect($customer->categoryAssignments()->whereNull('revoked_at')->count())->toBe(1);
});

test('bulk category action applies only explicit selected customers', function (): void {
    $user = staffManager();
    $category = CustomerCategory::factory()->create();
    $customers = Debtor::factory()->count(3)->create();

    Livewire::actingAs($user)->test(Categories::class)->call('members', $category->public_id)->set('selectedCustomers', [$customers[0]->id, $customers[1]->id])->call('applyMemberships')->assertHasNoErrors()->assertSee('Category operation applied to 2 explicitly selected customers.');

    $this->assertDatabaseCount('customer_category_assignments', 2);
    $this->assertDatabaseMissing('customer_category_assignments', ['customer_id' => $customers[2]->id]);
});

test('revoked permission blocks a previously opened customer form', function (): void {
    $user = staffManager();
    $customer = app(SaveCustomer::class)->handle($user, customerInput());
    $component = Livewire::actingAs($user)->test(Editor::class, ['customer' => $customer]);
    $user->syncRoles([]);

    $component->call('save')->assertForbidden();

    expect($customer->fresh()->display_name_ciphertext)->toBe('Nur Aisyah Tan');
});

test('an inactive category rolls back the entire customer creation', function (): void {
    $audits = AuditLog::query()->count();
    $user = staffManager();
    $inactiveCategory = CustomerCategory::factory()->create(['is_active' => false]);

    expect(fn () => app(SaveCustomer::class)->handle($user, customerInput(['category_ids' => [$inactiveCategory->id]])))->toThrow(ValidationException::class);

    $this->assertDatabaseCount('debtors', 0);
    $this->assertDatabaseCount('audit_logs', $audits);
});

test('customer update without contact grant preserves contacts and hides editor values', function (): void {
    $user = staffManager();
    $customer = app(SaveCustomer::class)->handle($user, customerInput(['contacts' => [['kind' => 'email', 'value' => 'private-contact@example.test', 'is_primary' => true]]]));
    $user->removeRole('Admin');
    $user->givePermissionTo(['admin.access', 'customers.view', 'customers.update']);

    Livewire::actingAs($user)->test(Editor::class, ['customer' => $customer])->assertSet('data.contacts', [])->assertDontSee('private-contact@example.test')
        ->set('data.name', 'Updated without contact privilege')->call('save')->assertHasNoErrors()->assertRedirect();

    expect($customer->fresh()->contacts()->whereNull('valid_to')->sole()->value_ciphertext)->toBe('private-contact@example.test');
});

test('bulk category operation rolls back all selections when one is stale', function (): void {
    $user = staffManager();
    $category = CustomerCategory::factory()->create();
    $customers = Debtor::factory()->count(2)->create();
    $component = Livewire::actingAs($user)->test(Categories::class)->call('members', $category->public_id)->set('selectedCustomers', $customers->pluck('id')->all());
    app(SaveCustomer::class)->handle($user, customerInput(['customer_reference' => $customers[1]->customer_reference, 'name' => 'Concurrent update']), $customers[1], $customers[1]->revision());

    $component->call('applyMemberships')->assertHasErrors('selectedCustomers');

    $this->assertDatabaseCount('customer_category_assignments', 0);
});

test('new customer reference duplicates are field errors', function (): void {
    $user = staffManager();
    app(SaveCustomer::class)->handle($user, customerInput());

    Livewire::actingAs($user)->test(Editor::class)->set('data', customerInput())->call('save')->assertHasErrors('data.customer_reference');

    $this->assertDatabaseCount('debtors', 1);
});
