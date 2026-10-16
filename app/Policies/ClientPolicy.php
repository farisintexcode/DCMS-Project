<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

/** Action permission never substitutes for the time-bound client scope. */
class ClientPolicy
{
    public function view(User $user, Client $client): bool
    {
        return $user->can('customers.view') && $user->hasClientScope($client);
    }
}
