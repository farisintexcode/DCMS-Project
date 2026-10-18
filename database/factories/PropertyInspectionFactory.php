<?php

namespace Database\Factories;

use App\Models\CollectionActivity;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Test fixtures require seeded approved M06 references and matching context overrides. */
class PropertyInspectionFactory extends Factory
{
    public function definition(): array
    {
        return ['activity_id' => CollectionActivity::factory(), 'task_id' => fn (array $a) => CollectionActivity::query()->findOrFail($a['activity_id'])->task_id, 'observed_at' => now(), 'business_type_code' => 'sole_proprietorship', 'business_nature_code' => 'retail', 'business_status_code' => 'active'];
    }
}
