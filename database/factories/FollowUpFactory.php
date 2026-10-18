<?php

namespace Database\Factories;

use App\Models\CollectionActivity;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Test fixtures require seeded approved M06 references and matching context overrides. */
class FollowUpFactory extends Factory
{
    public function definition(): array
    {
        return ['activity_id' => CollectionActivity::factory(), 'task_id' => fn (array $a) => CollectionActivity::query()->findOrFail($a['activity_id'])->task_id, 'customer_id' => fn (array $a) => CollectionActivity::query()->findOrFail($a['activity_id'])->customer_id, 'assigned_to' => fn (array $a) => CollectionActivity::query()->findOrFail($a['activity_id'])->performed_by, 'due_at' => now()->addDay(), 'type_code' => 'reminder', 'status' => 'pending', 'notes_ciphertext' => 'Synthetic follow-up'];
    }
}
