<?php

namespace App\Services\Tasks;

use App\Models\Task;
use App\Models\TaskStatusHistory;
use App\Models\User;
use App\Services\AuditWriter;

class TaskHistory
{
    public function record(User $actor, Task $task, string $event, ?string $from = null, ?string $review = null, ?int $allocation = null, ?string $reason = null): void
    {
        $history = new TaskStatusHistory;
        $history->forceFill(['task_id' => $task->id, 'actor_id' => $actor->id, 'event_code' => $event, 'from_work_status' => $from, 'to_work_status' => $task->work_status, 'from_review_status' => $review, 'to_review_status' => $task->review_status, 'allocation_id' => $allocation, 'reason' => $reason, 'occurred_at' => now()])->save();
        app(AuditWriter::class)->record($actor, 'task_'.$event, $task->public_id, ['status' => $task->work_status], module: 'M05', entityType: 'task');
    }
}
