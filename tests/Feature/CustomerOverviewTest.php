<?php

use App\Actions\Customers\SaveCustomer;
use App\Livewire\Admin\Home;
use App\Models\Debtor;
use App\Models\User;
use App\Services\Customers\CustomerOverview;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);
beforeEach(function (): void {
    $this->withoutVite();
    config(['dcms.search_key' => base64_encode(str_repeat('t', 32))]);
    $this->seed(DatabaseSeeder::class);
});

test('dashboard counts chart and activity stay within effective client scope', function (): void {
    $this->travelTo(now()->setTime(10, 0));
    [$user, $client] = customerOperator();
    $user->clientScopes()->update(['valid_from' => now()->subMinute()]);
    $customer = app(SaveCustomer::class)->handle($user, customerInput($client));
    Debtor::factory()->for($client)->create(['status' => 'inactive', 'created_at' => now()->subDays(2)]);
    Debtor::factory()->create(['display_name_ciphertext' => 'Foreign private customer']);

    $overview = app(CustomerOverview::class)->forUser($user);

    expect($overview['total'])->toBe(2)->and($overview['active'])->toBe(1)->and($overview['inactive'])->toBe(1)
        ->and($overview['clients'])->toBe(1)->and($overview['days'])->toHaveCount(7)
        ->and($overview['weekTotal'])->toBe(2)->and($overview['days']->last()['total'])->toBe(1)
        ->and($overview['recent']->pluck('client_id')->unique()->all())->toBe([$client->id])
        ->and($overview['activity']->pluck('entity_id')->unique()->all())->toBe([$customer->public_id]);
    Livewire::actingAs($user)->test(Home::class)->assertSee('Customer records created')->assertDontSee('Foreign private customer');
    $user->clientScopes()->update(['valid_to' => now()->subSecond()->format('Y-m-d H:i:s.uP')]);
    $revoked = app(CustomerOverview::class)->forUser($user);
    expect($revoked['total'])->toBe(0)->and($revoked['activity'])->toBeEmpty()->and($revoked['recent'])->toBeEmpty();
});

test('admin access without customer permission does not expose dashboard customer data', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo('admin.access');
    Debtor::factory()->create(['display_name_ciphertext' => 'Restricted customer name']);

    Livewire::actingAs($user)->test(Home::class)->assertSee('Your workspace is ready')->assertDontSee('Restricted customer name')->assertDontSee('Total customers');
    expect(fn () => app(CustomerOverview::class)->forUser($user))->toThrow(AuthorizationException::class);
});

test('creation chart groups local calendar days at the business timezone boundary', function (): void {
    $this->travelTo(now()->setTime(18, 0));
    [$user, $client] = customerOperator();
    $localMidnight = now()->timezone(config('dcms.business_timezone'))->startOfDay()->utc();
    Debtor::factory()->for($client)->create(['created_at' => $localMidnight->copy()->subSecond()]);
    Debtor::factory()->for($client)->create(['created_at' => $localMidnight->copy()->addSecond()]);

    $days = app(CustomerOverview::class)->forUser($user)['days'];

    expect($days->last()['total'])->toBe(1)->and($days[5]['total'])->toBe(1)->and($days->sum('total'))->toBe(2);
});
