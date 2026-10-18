<?php

namespace App\Policies;

use App\Models\Staff;
use App\Models\User;

/** M10/UI30–32: organization-level personnel access; client data remains separately scoped. */
class StaffPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive() && $user->can('admin.access') && $user->can('riders.manage');
    }

    public function view(User $user, Staff $staff): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Staff $staff): bool
    {
        return $this->viewAny($user);
    }

    public function invite(User $user, Staff $staff): bool
    {
        return $this->view($user, $staff) && $user->can('users.invite');
    }
}
