<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerCategoryFactory extends Factory
{
    public function definition(): array
    {
        return ['client_id' => Client::factory(), 'code' => fake()->unique()->bothify('CAT-######'), 'name' => fake()->words(2, true), 'is_active' => true];
    }
}
