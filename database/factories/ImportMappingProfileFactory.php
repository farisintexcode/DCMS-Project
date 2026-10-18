<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ImportTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ImportMappingProfileFactory extends Factory
{
    public function definition(): array
    {
        return ['client_id' => Client::factory(), 'template_id' => fn () => ImportTemplate::query()->where('kind', 'customer')->value('id'), 'code' => fake()->unique()->bothify('synthetic-######'), 'version' => 1, 'name' => 'Synthetic profile', 'mapping_definition' => [], 'created_by' => User::factory()];
    }
}
