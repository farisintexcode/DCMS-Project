<?php

use App\Models\Contact;
use App\Models\CustomerCategory;
use App\Models\CustomerCategoryAssignment;
use App\Models\Debtor;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);
beforeEach(function (): void {
    config(['dcms.search_key' => base64_encode(str_repeat('t', 32))]);
    $this->seed(DatabaseSeeder::class);
});

test('database blocks category membership across clients', function (): void {
    $customer = Debtor::factory()->create();
    $category = CustomerCategory::factory()->create();
    $user = User::factory()->create();

    expect(fn () => DB::transaction(fn () => CustomerCategoryAssignment::factory()->create(['customer_id' => $customer->id, 'category_id' => $category->id, 'assigned_by' => $user->id])))->toThrow(QueryException::class);

    $this->assertDatabaseCount('customer_category_assignments', 0);
});

test('database blocks overwriting historic contact observations', function (): void {
    $contact = Contact::factory()->create();

    expect(fn () => DB::transaction(fn () => DB::table('contacts')->where('id', $contact->id)->update(['value_ciphertext' => 'tampered'])))->toThrow(QueryException::class);

    expect($contact->fresh()->value_ciphertext)->not->toBe('tampered');
});

test('customer reference uniqueness is client scoped', function (): void {
    $customer = Debtor::factory()->create(['customer_reference' => 'C-001']);

    expect(fn () => DB::transaction(fn () => Debtor::factory()->create(['client_id' => $customer->client_id, 'customer_reference' => 'C-001'])))->toThrow(QueryException::class);
    Debtor::factory()->create(['customer_reference' => 'C-001']);

    $this->assertDatabaseCount('debtors', 2);
});
