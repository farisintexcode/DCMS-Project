<?php

namespace Database\Factories;

use App\Models\IntegrationAccount;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class IntegrationEventFactory extends Factory
{
    public function definition(): array
    {
        return ['integration_account_id' => IntegrationAccount::factory(), 'event_key' => (string) Str::uuid7(), 'event_type' => 'ses.notification', 'payload_ciphertext' => ['synthetic_fixture' => true], 'payload_digest' => hash('sha256', (string) Str::uuid7()), 'received_at' => now(), 'verified_at' => now(), 'state' => 'pending'];
    }
}
