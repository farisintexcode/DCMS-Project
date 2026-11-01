<?php

namespace App\Services\Tasks;

use App\Models\Staff;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;

/** Shared task query helpers. */
class TaskAccess
{
    /** Unfinished work past its own due time, or past the end of its batch's due date (business timezone) when it has none. */
    public function overdue(Builder $query): Builder
    {
        return $query->whereNotIn('work_status', ['completed', 'cancelled'])->where(fn ($q) => $q->where('due_at', '<', now())->orWhere(fn ($q) => $q->whereNull('due_at')->whereHas('batch', fn ($q) => $q->whereRaw('((due_date + 1)::timestamp AT TIME ZONE ?) <= ?', [config('dcms.business_timezone'), now()]))));
    }

    public function riders(): Builder
    {
        return Staff::query()->where('is_active', true)->where('is_rider', true)->whereHas('user', fn ($q) => $q->where('status', 'active')->where(fn ($q) => $q->whereHas('permissions', fn ($q) => $q->where('name', 'rider.access'))->orWhereHas('roles.permissions', fn ($q) => $q->where('name', 'rider.access'))));
    }
}
