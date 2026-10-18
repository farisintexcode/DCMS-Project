<?php

namespace Database\Factories;

use App\Models\DebtAccount;
use App\Models\Debtor;
use Illuminate\Database\Eloquent\Factories\Factory;

class AccountPartyFactory extends Factory
{
    public function definition(): array
    {
        return ['debt_account_id' => DebtAccount::factory(), 'debtor_id' => fn (array $a) => Debtor::factory()->create(['client_id' => DebtAccount::query()->findOrFail($a['debt_account_id'])->client_id])->id, 'role_code' => 'billing_customer', 'valid_from' => now()->subMinute()];
    }
}
