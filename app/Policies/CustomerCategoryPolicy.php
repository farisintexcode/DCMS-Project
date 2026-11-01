<?php

namespace App\Policies;

use App\Models\CustomerCategory;
use App\Models\User;

class CustomerCategoryPolicy
{
    public function manage(User $user): bool
    {
        return $user->isActive() && $user->can('customers.manage_categories');
    }

    public function update(User $user, CustomerCategory $category): bool
    {
        return $this->manage($user);
    }
}
