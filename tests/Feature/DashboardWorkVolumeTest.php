<?php

use App\Livewire\Admin\Home;
use App\Models\AssignmentBatch;
use App\Models\DebtAccount;
use App\Models\Task;
use App\Models\User;
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
    return Task::factory()->create(['assignment_batch_id' => $batch->id, 'debt_account_id' => DebtAccount::factory()->create()->id, ...$attributes]);
}

test('daily volume counts assignments and tasks per local day', function (): void {
    $user = staffManager();
    $today = AssignmentBatch::factory()->create();
    $localMidnight = now()->timezone(config('dcms.business_timezone'))->startOfDay()->utc();
    AssignmentBatch::factory()->create(['created_at' => $localMidnight->copy()->subSecond()]);
    AssignmentBatch::factory()->create(['created_at' => now()->subDays(2)]);
    AssignmentBatch::factory()->create(['created_at' => now()->subDays(10)]);
    volumeTask($today);
    volumeTask($today);

    $volume = app(WorkVolume::class)->forUser($user, 'daily', 7);

    expect($volume['buckets'])->toHaveCount(7)
        ->and($volume['buckets']->pluck('assignments')->all())->toBe([0, 0, 0, 0, 1, 1, 1])
        ->and($volume['buckets']->pluck('tasks')->all())->toBe([0, 0, 0, 0, 0, 0, 2])
        ->and($volume['buckets']->last()['date'])->toBe('31 Oct 2026')
        ->and($volume['assignments'])->toBe(3)->and($volume['tasks'])->toBe(2);
});

test('monthly volume groups by local month', function (): void {
    $user = staffManager();
    volumeTask(AssignmentBatch::factory()->create(['created_at' => '2026-08-15 04:00:00']), ['created_at' => '2026-08-15 04:00:00']);
    AssignmentBatch::factory()->create(['created_at' => '2026-07-20 04:00:00']);
    volumeTask(AssignmentBatch::factory()->create());

    $volume = app(WorkVolume::class)->forUser($user, 'monthly', 3);

    expect($volume['buckets']->pluck('label')->all())->toBe(['Aug', 'Sep', 'Oct'])
        ->and($volume['buckets']->pluck('assignments')->all())->toBe([1, 0, 1])
        ->and($volume['buckets']->pluck('tasks')->all())->toBe([1, 0, 1]);
});

test('dashboard filters switch the period and fall back to a supported range', function (): void {
    $user = staffManager();

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
