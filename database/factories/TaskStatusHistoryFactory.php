<?php

namespace Database\Factories;

use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TaskStatusHistoryFactory extends Factory
{
    public function definition(): array
    {
        return ['task_id' => Task::factory(), 'actor_id' => User::factory(), 'event_code' => 'created', 'to_work_status' => 'unstarted', 'to_review_status' => 'none', 'occurred_at' => now()];
    }
}
