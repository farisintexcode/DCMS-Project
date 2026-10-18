<?php

namespace App\Policies;

use App\Models\CollectionActivity;
use App\Models\User;
use App\Services\Calls\CallAccess;

class CollectionActivityPolicy
{
    public function viewAny(User $user): bool
    {
        return app(CallAccess::class)->allowed($user);
    }

    public function view(User $user, CollectionActivity $activity): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }
        $access = app(CallAccess::class);
        $customer = $access->customers($user)->find($activity->customer_id);

        return $customer !== null && $access->activities($user, $customer)->whereKey($activity->id)->exists();
    }
}
