<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

class PropertyFactory extends Factory
{
    public function definition(): array
    {
        return ['client_id' => Client::factory(), 'address_lines_ciphertext' => '10 Synthetic Road', 'postcode' => '00001'];
    }
}
