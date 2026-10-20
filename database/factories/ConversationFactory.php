<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Debtor;
use App\Models\IntegrationAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

class ConversationFactory extends Factory
{
    public function definition(): array
    {
        return ['customer_id' => Debtor::factory(), 'contact_id' => fn ($a) => Contact::factory()->create(['debtor_id' => $a['customer_id']])->id, 'integration_account_id' => IntegrationAccount::factory(), 'channel' => 'whatsapp'];
    }
}
