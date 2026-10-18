<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class CommunicationTemplateFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => 'Synthetic template', 'channel' => 'email', 'code' => 'email_1', 'email_slot' => 1, 'version' => 1, 'subject_template' => 'Synthetic subject for {{customer_name}}', 'body_template' => 'Synthetic message to {{customer_name}}.', 'allowed_placeholders' => ['customer_name' => ['required' => true]]];
    }
}
