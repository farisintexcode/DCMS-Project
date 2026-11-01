<?php

namespace Database\Factories;

use App\Models\AssignmentBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssignmentBatch>
 */
class AssignmentBatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'created_by' => User::factory(),
            'name' => 'Synthetic recovery batch',
            'external_reference' => fake()->unique()->bothify('B-######'),
            'type_code' => 'domestic_dr',
            'start_date' => '2026-10-01',
            'due_date' => '2026-10-31',
            'status' => 'active',
        ];
    }
}
