<?php

namespace Database\Factories;

use App\Models\CustomerCategory;
use App\Models\Debtor;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerCategoryAssignmentFactory extends Factory
{
    public function definition(): array
    {
        return ['customer_id' => Debtor::factory(), 'category_id' => fn (array $attributes) => CustomerCategory::factory()->create(['client_id' => Debtor::findOrFail($attributes['customer_id'])->client_id])->id, 'assigned_by' => User::factory(), 'assigned_at' => now()];
    }
}
