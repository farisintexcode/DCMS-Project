<?php

namespace App\Services\Tasks;

use App\Enums\BatchStatus;
use App\Models\AssignmentBatch;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** M06 / UI48–49: current assignments, fresh grants and batch-first write locks. */
class RiderAccess
{
    public function scope(Builder $query, User $actor): Builder
    {
        return $query->whereHas('currentAllocation', fn ($q) => $q->where('collector_id', $actor->staff_id));
    }

    public function current(User $actor, Task $task): bool
    {
        return $actor->canUseRiderInterface() && $actor->can('tasks.view') &&
            $task->currentAllocation()->where('collector_id', $actor->staff_id)->exists();
    }

    public function locked(User $actor, Task $context, ?int $version = null, bool $editable = true): Task
    {
        $actor = User::query()->findOrFail($actor->id);
        $batch = AssignmentBatch::query()->findOrFail($context->assignment_batch_id);
        $batch = AssignmentBatch::query()->lockForUpdate()->findOrFail($batch->id);
        $task = Task::query()->lockForUpdate()->findOrFail($context->id);
        abort_unless($this->current($actor, $task), 404);
        if ($editable) {
            Gate::forUser($actor)->authorize('execute', $task);
            if ($batch->status !== BatchStatus::Active || in_array($task->work_status, ['submitted', 'completed', 'cancelled'], true)) {
                throw ValidationException::withMessages(['status' => 'This task is read-only. Reload its current status.']);
            }
            if ($version !== null && $version !== $task->lock_version) {
                throw ValidationException::withMessages(['version' => 'This task changed. Reload and review before saving.']);
            }
        }

        return $task;
    }
}
