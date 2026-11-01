<?php

use App\Actions\Customers\SaveCustomer;
use App\Livewire\Admin\Customers\Index;
use App\Models\Debtor;
use App\Services\Customers\CustomerSearch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);
beforeEach(function (): void {
    $this->withoutVite();
    config(['dcms.search_key' => base64_encode(str_repeat('t', 32))]);
    $this->seed(DatabaseSeeder::class);
});

test('indexed search handles exact references identifiers contacts and name prefixes', function (string $kind, string $query, bool $found): void {
    $user = staffManager();
    $customer = app(SaveCustomer::class)->handle($user, customerInput(['identity' => '001122-33-4455', 'contacts' => [['kind' => 'email', 'value' => 'NuR@example.test', 'is_primary' => true]]]));

    $ids = app(CustomerSearch::class)->apply(Debtor::query(), $kind, $query)->pluck('id')->all();

    expect(in_array($customer->id, $ids))->toBe($found);
})->with([
    ['reference', 'C-001', true], ['reference', 'C-', false], ['name', 'aisy TAN', true], ['name', 'ai', false], ['name', 'missing', false],
    ['identifier', '001122334455', true], ['identifier', '1122334455', false], ['contact', 'nur@EXAMPLE.test', true], ['contact', 'nur@', false],
]);

test('list search shows every customer and manipulated filters stay safe', function (): void {
    $user = staffManager();
    app(SaveCustomer::class)->handle($user, customerInput());
    Debtor::factory()->create(['display_name_ciphertext' => 'Second listed record']);

    Livewire::actingAs($user)->test(Index::class)->assertSee('Nur Aisyah Tan')->assertSee('Second listed record')
        ->set('status', 'invalid-sql')->assertOk()->assertDontSee('Nur Aisyah Tan');
});

test('keyed tokens omit plaintext and missing key fails closed', function (): void {
    $user = staffManager();
    $customer = app(SaveCustomer::class)->handle($user, customerInput());
    expect(json_encode($customer->search_tokens))->not->toContain('aisyah', 'nur', 'tan');
    config(['dcms.search_key' => null]);

    expect(fn () => app(CustomerSearch::class)->names('Private Name'))->toThrow(LogicException::class);
});
