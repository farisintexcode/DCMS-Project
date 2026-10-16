<?php

use App\Models\Client;
use App\Models\User;
use App\Models\UserClientScope;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

function customerOperator(): array
{
    $user = User::factory()->create();
    $user->assignRole('Admin');
    $client = Client::factory()->create();
    $scope = new UserClientScope;
    $scope->user_id = $user->id;
    $scope->client_id = $client->id;
    $scope->granted_by = $user->id;
    $scope->reason = 'Synthetic customer test scope';
    $scope->save();

    return [$user, $client];
}

function customerInput(Client $client, array $overrides = []): array
{
    return array_replace(['client_id' => $client->id, 'customer_reference' => 'C-001', 'party_type' => 'person', 'name' => 'Nur Aisyah Tan', 'identity' => '', 'status' => 'active', 'contacts' => [], 'addresses' => [], 'category_ids' => []], $overrides);
}
