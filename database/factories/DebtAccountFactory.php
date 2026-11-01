<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class DebtAccountFactory extends Factory
{
    public function definition(): array
    {
        return ['account_number' => fake()->unique()->bothify('000######'), 'currency' => 'MYR', 'status' => 'active'];
    }
}
