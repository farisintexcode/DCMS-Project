<?php

namespace Database\Factories;

use App\Models\AccountParty;
use App\Models\AssignmentBatch;
use App\Models\DebtAccount;
use App\Models\Debtor;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TaskFactory extends Factory
{
    public function definition(): array
    {
        return ['debt_account_id' => DebtAccount::factory(), 'assignment_batch_id' => AssignmentBatch::factory(), 'customer_id' => function (array $a): int {
            $account = DebtAccount::query()->findOrFail($a['debt_account_id']);
            $customer = Debtor::factory()->create();
            AccountParty::factory()->create(['debt_account_id' => $account->id, 'debtor_id' => $customer->id]);

            return $customer->id;
        }, 'created_by' => User::factory(), 'type_code' => 'domestic_dr', 'origin_code' => 'manual', 'source_snapshot_ciphertext' => ['origin' => 'manual', 'fixture' => 'synthetic'], 'priority_code' => 'normal', 'work_status' => 'unstarted', 'review_status' => 'none'];
    }
}
