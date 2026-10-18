<?php

namespace Database\Factories;

use App\Models\AssignmentBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class BulkOperationFactory extends Factory
{
    public function definition(): array
    {
        return ['assignment_batch_id' => AssignmentBatch::factory(), 'requested_by' => User::factory(), 'operation_code' => 'cancel', 'selection_mode' => 'single', 'reason' => 'Synthetic cancellation', 'preview_digest' => hash('sha256', 'synthetic'), 'idempotency_key' => fake()->uuid(), 'status' => 'previewed'];
    }
}
