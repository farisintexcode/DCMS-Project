<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class IntegrationAccountFactory extends Factory
{
    public function definition(): array
    {
        return ['provider_code' => 'synthetic', 'channel' => 'email', 'name' => fake()->unique()->word(), 'secret_reference' => 'synthetic/not-a-credential', 'status' => 'disabled', 'capabilities' => []];
    }
}
