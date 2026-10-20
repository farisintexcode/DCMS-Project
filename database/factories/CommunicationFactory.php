<?php

namespace Database\Factories;

use App\Models\CommunicationBatch;
use App\Models\Debtor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CommunicationFactory extends Factory
{
    public function definition(): array
    {
        return ['batch_id' => CommunicationBatch::factory(), 'customer_id' => fn ($a) => Debtor::factory()->create(['client_id' => CommunicationBatch::query()->findOrFail($a['batch_id'])->client_id])->id, 'template_id' => fn ($a) => CommunicationBatch::query()->findOrFail($a['batch_id'])->template_id, 'channel' => 'email', 'direction' => 'outgoing', 'recipient_ciphertext' => 'synthetic@example.test', 'subject_ciphertext' => 'Synthetic preview', 'body_ciphertext' => 'Synthetic unsent content', 'context_snapshot_ciphertext' => [], 'delivery_status' => 'draft', 'idempotency_key' => (string) Str::uuid7()];
    }
}
