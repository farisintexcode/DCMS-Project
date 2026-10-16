<?php

namespace App\Services\Customers;

use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** No cached portfolio list: every query rechecks effective time-bound grants. */
class CustomerAccess
{
    public function clients(User $user): Builder
    {
        $instant = now()->format('Y-m-d H:i:s.uP');

        return Client::query()->where('is_active', true)->whereHas('userScopes', fn (Builder $q) => $q
            ->where('user_id', $user->id)->where('valid_from', '<=', $instant)
            ->where(fn (Builder $q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', $instant)));
    }

    public function scope(Builder $query, User $user): Builder
    {
        return $query->whereIn('client_id', $this->clients($user)->select('clients.id'));
    }
}
