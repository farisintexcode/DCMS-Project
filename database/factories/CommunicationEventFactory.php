<?php

namespace Database\Factories;

use App\Models\Communication;
use Illuminate\Database\Eloquent\Factories\Factory;

class CommunicationEventFactory extends Factory
{
    public function definition(): array
    {
        return ['communication_id' => Communication::factory(), 'event_code' => 'previewed', 'occurred_at' => now(), 'received_at' => now(), 'details' => []];
    }
}
