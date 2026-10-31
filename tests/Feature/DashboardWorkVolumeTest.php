<?php

use App\Livewire\Admin\Home;
use App\Models\AssignmentBatch;
use App\Models\Client;
use App\Models\DebtAccount;
use App\Models\Task;
use App\Models\User;
use App\Models\UserClientScope;
use App\Services\Tasks\WorkVolume;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);
beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(DatabaseSeeder::class);
    $this->travelTo('2026-10-31 04:00:00');
});

function volumeTask(AssignmentBatch $batch, array $attributes = []): Task
{
    return Task::factory()->create(['assignment_batch_id' => $batch->id, 'debt_account_id' => DebtAccount::factory()->create(['client_id' => $batch->client_id])->id, ...$attributes]);
}

test('daily volume counts assignments and tasks per local day within client scope', function (): void {
    [$user, $client] = customerOperator();
    $today = AssignmentBatch::factory()->create(['client_id' => $client->id]);
    $localMidnight = now()->timezone(config('dcms.business_timezone'))->startOfDay()->utc();
    AssignmentBatch::factory()->create(['client_id' => $client->id, 'created_at' => $localMidnight->copy()->subSecond()]);
    AssignmentBatch::factory()->create(['client_id' => $client->id, 'created_at' => now()->subDays(2)]);
    AssignmentBatch::factory()->create(['client_id' => $client->id, 'created_at' => now()->subDays(10)]);
    volumeTask($today);
    volumeTask($today);
    volumeTask(AssignmentBatch::factory()->create());

    $volume = app(WorkVolume::class)->forUser($user, 'daily', 7);

    expect($volume['buckets'])->toHaveCount(7)
        ->and($volume['buckets']->pluck('assignments')->all())->toBe([0, 0, 0, 0, 1, 1, 1])
        ->and($volume['buckets']->pluck('tasks')->all())->toBe([0, 0, 0, 0, 0, 0, 2])
        ->and($volume['buckets']->last()['date'])->toBe('31 Oct 2026')
        ->and($volume['assignments'])->toBe(3)->and($volume['tasks'])->toBe(2);
});

test('monthly volume groups by local month and the client filter narrows both series', function (): void {
    [$user, $client] = customerOperator();
    $other = Client::factory()->create();
    $scope = new UserClientScope;
    $scope->user_id = $user->id;
    $scope->client_id = $other->id;
    $scope->granted_by = $user->id;
    $scope->reason = 'Synthetic second client scope';
    $scope->valid_from = now()->subMinute();
    $scope->save();
    volumeTask(AssignmentBatch::factory()->create(['client_id' => $client->id, 'created_at' => '2026-08-15 04:00:00']), ['created_at' => '2026-08-15 04:00:00']);
    AssignmentBatch::factory()->create(['client_id' => $client->id, 'created_at' => '2026-07-20 04:00:00']);
    volumeTask(AssignmentBatch::factory()->create(['client_id' => $other->id]));

    $all = app(WorkVolume::class)->forUser($user, 'monthly', 3);
    $filtered = app(WorkVolume::class)->forUser($user, 'monthly', 3, $client->id);

    expect($all['buckets']->pluck('label')->all())->toBe(['Aug', 'Sep', 'Oct'])
        ->and($all['buckets']->pluck('assignments')->all())->toBe([1, 0, 1])
        ->and($all['buckets']->pluck('tasks')->all())->toBe([1, 0, 1])
        ->and($filtered['buckets']->pluck('assignments')->all())->toBe([1, 0, 0])
        ->and($filtered['buckets']->pluck('tasks')->all())->toBe([1, 0, 0]);
});

test('dashboard filters switch the period and fall back to a supported range', function (): void {
    [$user] = customerOperator();

    Livewire::actingAs($user)->test(Home::class)
        ->assertSee('Assignments and tasks created')->assertViewHas('volumeRange', 7)
        ->set('period', 'monthly')->assertSet('range', '12')->assertViewHas('volumeRange', 12)
        ->set('range', '99')->assertViewHas('volumeRange', 3);
});

test('admin access without assignment or task permission does not show work volume', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo('admin.access');

    expect(app(WorkVolume::class)->forUser($user, 'daily', 7))->toBeNull();
    Livewire::actingAs($user)->test(Home::class)->assertDontSee('Assignments and tasks created');
});
