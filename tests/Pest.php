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
    $scope->valid_from = now()->subMinute();
    $scope->save();

    return [$user, $client];
}

function customerInput(Client $client, array $overrides = []): array
{
    return array_replace(['client_id' => $client->id, 'customer_reference' => 'C-001', 'party_type' => 'person', 'name' => 'Nur Aisyah Tan', 'identity' => '', 'status' => 'active', 'contacts' => [], 'addresses' => [], 'category_ids' => []], $overrides);
}

function staffManager(): User
{
    $user = User::factory()->create();
    $user->assignRole('Admin');

    return $user;
}

function staffInput(array $overrides = []): array
{
    return array_replace(['staff_no' => '000123', 'first_name' => 'Nadia', 'last_name' => 'Test', 'email' => 'nadia@example.test', 'gender_code' => 'female', 'position_code' => '01', 'appointment_date' => '2026-01-02', 'phone' => '+60123456789', 'home_phone' => '', 'address' => '10 Synthetic Test Road', 'is_active' => true, 'is_rider' => true, 'reason' => ''], $overrides);
}

require_once __DIR__.'/CommunicationFixtures.php';
