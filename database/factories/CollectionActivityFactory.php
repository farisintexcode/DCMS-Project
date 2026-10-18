<?php

namespace Database\Factories;

use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** Test fixtures require seeded approved M06 references and matching context overrides. */
class CollectionActivityFactory extends Factory
{
    public function definition(): array
    {
        return ['task_id' => Task::factory(), 'customer_id' => fn (array $a) => Task::query()->findOrFail($a['task_id'])->customer_id, 'performed_by' => User::factory(), 'activity_type_code' => 'visit', 'visit_purpose_code' => 'site_visit', 'occurred_at' => now(), 'availability_code' => 'available', 'visit_status_code' => 'operating', 'contact_result_code' => 'customer_contacted', 'outcome_code' => 'successful_visit', 'site_address_ciphertext' => 'Synthetic visit address', 'visit_remarks_ciphertext' => 'Synthetic visit remarks', 'idempotency_key' => (string) Str::uuid7()];
    }
}
