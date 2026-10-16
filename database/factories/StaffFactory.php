<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class StaffFactory extends Factory
{
    public function definition(): array
    {
        return ['staff_no' => fake()->unique()->bothify('S-#######'), 'first_name' => fake()->firstName(), 'last_name' => fake()->lastName(), 'email' => fake()->safeEmail(), 'position_code' => '01', 'is_active' => true, 'is_rider' => true];
    }
}
