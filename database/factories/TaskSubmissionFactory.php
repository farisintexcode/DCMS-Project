<?php

namespace Database\Factories;

use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** Test fixtures require seeded approved M06 references and matching context overrides. */
class TaskSubmissionFactory extends Factory
{
    public function definition(): array
    {
        return ['task_id' => Task::factory()->state(['work_status' => 'in_progress']), 'submitted_by' => User::factory(), 'submitted_at' => now(), 'submission_version' => 1, 'task_version' => 1, 'completion_snapshot_ciphertext' => ['fixture' => 'synthetic'], 'idempotency_key' => (string) Str::uuid7()];
    }
}
