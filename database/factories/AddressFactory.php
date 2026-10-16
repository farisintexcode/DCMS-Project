<?php

namespace Database\Factories;

use App\Models\Debtor;
use Illuminate\Database\Eloquent\Factories\Factory;

class AddressFactory extends Factory
{
    public function definition(): array
    {
        return ['debtor_id' => Debtor::factory(), 'kind_code' => 'billing', 'lines_ciphertext' => fake()->address(), 'source' => 'synthetic-fixture', 'valid_from' => now()];
    }
}
