<?php

namespace Database\Factories;

use App\Models\TaskSubmission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Synthetic independent review context; application reviews always use ReviewSubmission. */
class TaskReviewFactory extends Factory
{
    public function definition(): array
    {
        return ['submission_id' => function (): int {
            $submission = TaskSubmission::factory()->create();
            $submission->task->forceFill(['work_status' => 'submitted', 'review_status' => 'pending', 'lock_version' => $submission->task_version])->save();

            return $submission->id;
        }, 'task_id' => fn (array $a) => TaskSubmission::query()->findOrFail($a['submission_id'])->task_id,
            'submission_version' => fn (array $a) => TaskSubmission::query()->findOrFail($a['submission_id'])->submission_version,
            'reviewer_id' => User::factory(), 'decision' => 'approved', 'reviewed_at' => now()];
    }
}
