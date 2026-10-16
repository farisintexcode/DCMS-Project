<?php

use App\Livewire\Admin\Home;
use App\Models\Client;
use App\Models\Staff;
use App\Models\User;
use App\Models\UserClientScope;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(DatabaseSeeder::class);
});

test('guests are directed to the corresponding entry point', function (): void {
    $this->get(route('admin.home'))->assertRedirect(route('login'));
    $this->get(route('rider.home'))->assertRedirect(route('rider.login'));
});

test('rider cannot enter admin or gain admin through a login payload', function (): void {
    $user = User::factory()->for(Staff::factory()->create())->create();
    $user->assignRole('Rider');

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password', 'role' => 'Admin'])->assertSessionHasErrors('email');
    $this->actingAs($user)->get(route('admin.home'))->assertForbidden();
    $this->get(route('rider.home'))->assertOk()->assertSee('Rider workspace');

    expect($user->fresh()->hasRole('Admin'))->toBeFalse();
});

test('admin bundle does not imply rider eligibility or approval authority', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Admin');

    $this->actingAs($user)->get(route('admin.home'))->assertOk();
    $this->get(route('rider.home'))->assertForbidden();

    expect($user->can('attendance.approve_correction'))->toBeFalse();
    expect($user->can('templates.approve'))->toBeFalse();
    expect($user->can('roles.manage'))->toBeFalse();
});

test('client action requires a current effective grant', function (string $validity, bool $allowed): void {
    $this->freezeTime();
    $user = User::factory()->create();
    $user->assignRole('Admin');
    $client = Client::factory()->create();
    if ($validity !== 'missing') {
        $scope = new UserClientScope;
        $scope->user_id = $user->id;
        $scope->client_id = $client->id;
        $scope->granted_by = $user->id;
        $scope->reason = 'Synthetic portfolio grant';
        $scope->valid_from = $validity === 'future' ? now()->addDay() : now()->subDays(2);
        $scope->valid_to = $validity === 'expired' ? now()->subDay() : null;
        $scope->save();
    }

    expect(Gate::forUser($user)->allows('view', $client))->toBe($allowed);
    expect(Gate::forUser($user)->allows('view', Client::factory()->create()))->toBeFalse();
})->with([['missing', false], ['future', false], ['expired', false], ['current', true]]);

test('revoked action grant is rechecked by livewire', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo('admin.access');
    $component = Livewire::actingAs($user)->test(Home::class)->assertOk();
    $user->revokePermissionTo('admin.access');

    $component->call('$refresh')->assertForbidden();
});

test('untrusted display names are escaped in the shell', function (): void {
    $user = User::factory()->create(['name' => '<script>alert(1)</script>']);
    $user->assignRole('Admin');

    $this->actingAs($user)->get(route('admin.home'))->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
});

test('monitoring has no local role bypass', function (): void {
    $user = User::factory()->for(Staff::factory()->create())->create();
    $user->assignRole('Rider');

    $this->actingAs($user)->get('/pulse')->assertForbidden();
});

test('client scope does not grant the action permission', function (): void {
    $user = User::factory()->create();
    $client = Client::factory()->create();
    $scope = new UserClientScope;
    $scope->user_id = $user->id;
    $scope->client_id = $client->id;
    $scope->granted_by = $user->id;
    $scope->reason = 'Synthetic portfolio grant';
    $scope->save();

    expect(Gate::forUser($user)->allows('view', $client))->toBeFalse();
});
