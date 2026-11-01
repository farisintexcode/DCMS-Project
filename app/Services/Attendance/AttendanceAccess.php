<?php

namespace App\Services\Attendance;

use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** Plan.md attendance scope; personnel itself remains organization-wide. */
class AttendanceAccess
{
    public function riders(User $actor): Builder
    {
        return Staff::query()->where('is_rider', true);
    }

    public function admin(User $actor, string $permission = 'attendance.view_all'): bool
    {
        return $actor->isActive() && $actor->can('admin.access') && $actor->can($permission);
    }

    public function visible(User $actor, int $rider): bool
    {
        return $this->admin($actor) && $this->riders($actor)->whereKey($rider)->exists();
    }
}
