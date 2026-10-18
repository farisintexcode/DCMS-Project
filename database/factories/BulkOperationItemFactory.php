<?php

namespace Database\Factories;

use App\Models\BulkOperation;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

class BulkOperationItemFactory extends Factory
{
    public function definition(): array
    {
        return ['task_id' => Task::factory(), 'bulk_operation_id' => fn (array $a) => BulkOperation::factory()->create(['assignment_batch_id' => Task::query()->findOrFail($a['task_id'])->assignment_batch_id])->id, 'expected_version' => 0, 'state' => 'pending'];
    }
}
