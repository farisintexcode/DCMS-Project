<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ImportTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ImportBatchFactory extends Factory
{
    public function definition(): array
    {
        return ['client_id' => Client::factory(), 'uploaded_by' => User::factory(), 'template_id' => fn () => ImportTemplate::query()->where('kind', 'customer')->firstOrFail()->id, 'kind' => 'customer', 'content_sha256' => hash('sha256', 'synthetic-workbook'), 'idempotency_key' => fake()->uuid(), 'status' => 'uploaded'];
    }
}
