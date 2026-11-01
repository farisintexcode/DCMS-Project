<?php

namespace Database\Factories;

use App\Models\DebtAccount;
use App\Models\Debtor;
use Illuminate\Database\Eloquent\Factories\Factory;

class AccountPartyFactory extends Factory
{
    public function definition(): array
    {
        return ['debt_account_id' => DebtAccount::factory(), 'debtor_id' => Debtor::factory(), 'role_code' => 'billing_customer', 'valid_from' => now()->subMinute()];
    }
}
