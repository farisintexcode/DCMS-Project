<?php

namespace Database\Factories;

use App\Services\Customers\CustomerSearch;
use Illuminate\Database\Eloquent\Factories\Factory;

class DebtorFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->name();

        return ['customer_reference' => fake()->unique()->bothify('C-########'), 'party_type' => 'person', 'display_name_ciphertext' => $name, 'search_tokens' => app(CustomerSearch::class)->names($name), 'status' => 'active'];
    }
}
