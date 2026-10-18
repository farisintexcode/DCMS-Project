<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

class DebtAccountFactory extends Factory
{
    public function definition(): array
    {
        return ['client_id' => Client::factory(), 'account_number' => fake()->unique()->bothify('000######'), 'currency' => 'MYR', 'status' => 'active'];
    }
}
