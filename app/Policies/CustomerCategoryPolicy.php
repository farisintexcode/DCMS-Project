<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\CustomerCategory;
use App\Models\User;

class CustomerCategoryPolicy
{
    public function manage(User $user, Client $client): bool
    {
        return $user->isActive() && $user->can('customers.manage_categories') && $user->hasClientScope($client);
    }

    public function update(User $user, CustomerCategory $category): bool
    {
        return $this->manage($user, $category->client);
    }
}
