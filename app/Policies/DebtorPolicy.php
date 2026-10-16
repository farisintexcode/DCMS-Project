<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\Debtor;
use App\Models\User;

class DebtorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive() && $user->can('customers.view');
    }

    public function view(User $user, Debtor $customer): bool
    {
        return $this->viewAny($user) && $user->hasClientScope($customer->client);
    }

    public function create(User $user, Client $client): bool
    {
        return $user->isActive() && $user->can('customers.create') && $user->hasClientScope($client);
    }

    public function update(User $user, Debtor $customer): bool
    {
        return $this->view($user, $customer) && $user->can('customers.update');
    }

    public function categorize(User $user, Debtor $customer): bool
    {
        return $this->view($user, $customer) && $user->can('customers.categorize');
    }
}
