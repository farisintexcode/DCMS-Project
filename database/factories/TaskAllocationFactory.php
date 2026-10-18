<?php

namespace Database\Factories;

use App\Models\Staff;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TaskAllocationFactory extends Factory
{
    public function definition(): array
    {
        return ['task_id' => Task::factory(), 'collector_id' => Staff::factory(), 'assigned_by' => User::factory(), 'operation_code' => 'new', 'assigned_at' => now()];
    }
}
