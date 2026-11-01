<?php

namespace App\Actions\Assignments;

use App\Enums\BatchStatus;
use App\Models\AssignmentBatch;
use App\Models\User;
use App\Services\Assignments\BatchWork;
use App\Services\AuditWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ChangeBatchStatus
{
    public function __construct(private AuditWriter $audit, private BatchWork $work) {}

    public function handle(User $actor, AssignmentBatch $target, string $status, string $reason, int $version): AssignmentBatch
    {
        return DB::transaction(function () use ($actor, $target, $status, $reason, $version): AssignmentBatch {
            abort_unless($actor->isActive(), 404);
            $batch = AssignmentBatch::query()->lockForUpdate()->findOrFail($target->id);
            Gate::forUser($actor)->authorize('update', $batch);
            Validator::make(['targetStatus' => $status, 'reason' => trim($reason)], ['targetStatus' => ['required', 'in:active,archived'], 'reason' => ['required', 'string', 'max:1000']])->validate();
            if ($batch->lock_version !== $version) {
                throw ValidationException::withMessages(['version' => 'This assignment changed. Reload and review before changing status.']);
            }
            if ($batch->status->value === $status) {
                throw ValidationException::withMessages(['targetStatus' => 'This assignment already has that status.']);
            }
            if ($status === 'active') {
                Gate::forUser($actor)->authorize('reopen', $batch);
            } else {
                $work = $this->work->summary($batch);
                if ($work['unfinished_tasks'] > 0 || $work['unfinished_imports'] > 0) {
                    throw ValidationException::withMessages(['targetStatus' => 'Resolve outstanding tasks and finish or reconcile pending imports before archiving.']);
                }
            }
            $previous = $batch->status->value;
            $batch->status = BatchStatus::from($status);
            $batch->archived_at = $status === 'archived' ? now() : null;
            $batch->lock_version++;
            $batch->save();
            $this->audit->record($actor, $status === 'archived' ? 'assignment_archived' : 'assignment_reopened', $batch->public_id, ['status' => $status], trim($reason), 'M03', 'assignment_batch', ['status' => $previous]);

            return $batch->refresh();
        }, 3);
    }
}
