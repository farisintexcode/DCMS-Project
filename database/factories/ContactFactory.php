<?php

namespace Database\Factories;

use App\Models\Debtor;
use App\Services\Customers\CustomerSearch;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContactFactory extends Factory
{
    public function definition(): array
    {
        $value = fake()->safeEmail();

        return ['debtor_id' => Debtor::factory(), 'kind_code' => 'email', 'value_ciphertext' => $value, 'value_fingerprint' => app(CustomerSearch::class)->token('contact:email', mb_strtolower($value)), 'source' => 'synthetic-fixture', 'is_primary' => true, 'valid_from' => now()];
    }
}
