<?php

namespace App\Services\Tasks;

use App\Models\Client;
use App\Models\Staff;
use App\Models\Task;
use App\Models\User;
use App\Services\ClientAccess;
use Illuminate\Database\Eloquent\Builder;

/** All task queries derive portfolio scope through their canonical parent batch. */
class TaskAccess
{
    public function scope(Builder $query, User $actor): Builder
    {
        return $query->whereHas('batch', fn ($q) => app(ClientAccess::class)->scope($q, $actor));
    }

    public function riders(Client $client): Builder
    {
        return Staff::query()->where('is_active', true)->where('is_rider', true)->whereHas('user', fn ($q) => $q->where('status', 'active')->where(fn ($q) => $q->whereHas('permissions', fn ($q) => $q->where('name', 'rider.access'))->orWhereHas('roles.permissions', fn ($q) => $q->where('name', 'rider.access')))->whereHas('clientScopes', fn ($q) => $q->where('client_id', $client->id)->where('valid_from', '<=', now()->format('Y-m-d H:i:s.uP'))->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', now()->format('Y-m-d H:i:s.uP')))));
    }
}
