<?php

namespace Database\Factories;

use App\Models\CommunicationAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

class CommunicationQuotaReservationFactory extends Factory
{
    public function definition(): array
    {
        return ['attempt_id' => CommunicationAttempt::factory(), 'bucket_key' => 'email:application', 'reserved_at' => now(), 'state' => 'reserved'];
    }
}
