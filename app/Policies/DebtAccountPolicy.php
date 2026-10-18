<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\DebtAccount;
use App\Models\User;

class DebtAccountPolicy
{
    public function view(User $user, DebtAccount $account): bool
    {
        return $user->isActive() && $user->can('admin.access') && $user->can('customers.view') && $user->hasClientScope($account->client);
    }

    public function create(User $user, Client $client): bool
    {
        return $user->isActive() && $user->can('admin.access') && $user->can('customers.update') && $user->can('tasks.create') && $user->hasClientScope($client);
    }

    public function update(User $user, DebtAccount $account): bool
    {
        return $this->view($user, $account) && $this->create($user, $account->client);
    }
}
