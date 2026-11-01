<?php

namespace Database\Factories;

use App\Models\CommunicationTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CommunicationBatchFactory extends Factory
{
    public function definition(): array
    {
        return ['template_id' => fn () => CommunicationTemplate::query()->where('status', 'approved')->first()?->id ?? CommunicationTemplate::factory()->create()->id, 'requested_by' => User::factory(), 'selection_criteria' => [], 'counts' => (object) [], 'idempotency_key' => (string) Str::uuid7()];
    }
}
