<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerCategoryFactory extends Factory
{
    public function definition(): array
    {
        return ['code' => fake()->unique()->bothify('CAT-######'), 'name' => fake()->words(2, true), 'is_active' => true];
    }
}
