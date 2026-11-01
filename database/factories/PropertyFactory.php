<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class PropertyFactory extends Factory
{
    public function definition(): array
    {
        return ['address_lines_ciphertext' => '10 Synthetic Road', 'postcode' => '00001'];
    }
}
