<?php

use App\Models\Contact;
use App\Models\Debtor;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);
beforeEach(function (): void {
    config(['dcms.search_key' => base64_encode(str_repeat('t', 32))]);
    $this->seed(DatabaseSeeder::class);
});

test('database blocks overwriting historic contact observations', function (): void {
    $contact = Contact::factory()->create();

    expect(fn () => DB::transaction(fn () => DB::table('contacts')->where('id', $contact->id)->update(['value_ciphertext' => 'tampered'])))->toThrow(QueryException::class);

    expect($contact->fresh()->value_ciphertext)->not->toBe('tampered');
});

test('customer reference uniqueness is system-wide', function (): void {
    Debtor::factory()->create(['customer_reference' => 'C-001']);

    expect(fn () => DB::transaction(fn () => Debtor::factory()->create(['customer_reference' => 'C-001'])))->toThrow(QueryException::class);

    $this->assertDatabaseCount('debtors', 1);
});
