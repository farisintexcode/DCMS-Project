<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ClientFactory extends Factory
{
    public function definition(): array
    {
        return ['code' => fake()->unique()->bothify('CL-########'), 'name' => fake()->company(), 'is_active' => true, 'default_currency' => 'MYR'];
    }
}
