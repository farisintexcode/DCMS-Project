<?php

namespace Database\Factories;

use App\Models\Debtor;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CommunicationPreferenceFactory extends Factory
{
    public function definition(): array
    {
        return ['customer_id' => Debtor::factory(), 'state' => 'unknown', 'evidence_ciphertext' => ['source' => 'synthetic', 'reference' => 'TEST-ONLY', 'email_source' => 'synthetic'], 'reason_ciphertext' => 'Synthetic evidence request', 'effective_at' => now(), 'recorded_by' => User::factory(), 'request_key' => (string) Str::uuid7()];
    }
}
