<?php

namespace Database\Factories;

use App\Models\Communication;
use App\Models\IntegrationAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CommunicationAttemptFactory extends Factory
{
    public function definition(): array
    {
        return ['integration_account_id' => IntegrationAccount::factory(), 'communication_id' => fn (array $a) => Communication::factory()->create(['integration_account_id' => $a['integration_account_id'], 'sent_by' => User::factory(), 'delivery_status' => 'queued'])->id, 'attempt_number' => 1, 'request_key' => (string) Str::uuid7(), 'started_at' => now(), 'state' => 'pending'];
    }
}
