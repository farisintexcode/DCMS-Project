<?php

namespace App\Policies;

use App\Models\Attendance;
use App\Models\User;
use App\Services\Attendance\AttendanceAccess;

class AttendancePolicy
{
    public function viewAny(User $user): bool
    {
        return app(AttendanceAccess::class)->admin($user);
    }

    public function view(User $user, Attendance $attendance): bool
    {
        return ($user->isActive() && $user->staff_id === $attendance->rider_id && $user->can('attendance.view_own')) || app(AttendanceAccess::class)->visible($user, $attendance->rider_id);
    }

    public function clock(User $user): bool
    {
        return $user->isActive() && $user->staff_id !== null && $user->can('attendance.clock');
    }

    public function configure(User $user): bool
    {
        return $this->viewAny($user) && $user->can('attendance.configure');
    }

    public function export(User $user): bool
    {
        return $this->viewAny($user) && $user->can('attendance.export');
    }
}
