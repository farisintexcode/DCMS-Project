<?php

namespace App\Services\Attendance;

use App\Models\Staff;
use App\Models\User;
use App\Services\ClientAccess;
use Illuminate\Database\Eloquent\Builder;

/** Plan.md attendance scope; personnel itself remains organization-wide. */
class AttendanceAccess
{
    public function __construct(private ClientAccess $clients) {}

    public function riders(User $actor, ?string $client = null): Builder
    {
        $instant = now()->format('Y-m-d H:i:s.uP');
        $clients = $this->clients->clients($actor)->when($client !== null && $client !== '', fn ($q) => $q->where('public_id', $client));

        return Staff::query()->whereHas('user.clientScopes', fn ($q) => $q
            ->whereIn('client_id', $clients->select('clients.id'))->where('valid_from', '<=', $instant)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', $instant)));
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
