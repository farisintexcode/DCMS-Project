<?php

namespace Database\Factories;

use App\Models\ImportField;
use Illuminate\Database\Eloquent\Factories\Factory;

class ColumnAliasFactory extends Factory
{
    public function definition(): array
    {
        return ['module' => 'customer', 'field_id' => ImportField::factory(), 'alias' => 'Synthetic Header', 'normalized_alias' => 'synthetic header', 'is_active' => true];
    }
}
