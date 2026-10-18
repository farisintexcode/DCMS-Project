<?php

namespace App\Services\Customers;

use App\Models\AuditLog;
use App\Models\Debtor;
use App\Models\User;
use App\Services\ClientAccess;
use Illuminate\Support\Facades\Gate;

/** UI07/M13: read-only customer metrics; the same permission and portfolio scope as UI62. */
class CustomerOverview
{
    public function __construct(private ClientAccess $access) {}

    public function forUser(User $user): array
    {
        Gate::forUser($user)->authorize('viewAny', Debtor::class);
        $customers = $this->access->scope(Debtor::query(), $user);
        $totals = (clone $customers)->selectRaw("count(*) as total, count(*) filter (where status = 'active') as active, count(*) filter (where status = 'inactive') as inactive")->first();
        $today = now()->timezone(config('dcms.business_timezone'))->startOfDay();
        $counts = (clone $customers)->where('created_at', '>=', $today->copy()->subDays(6)->utc())
            ->where('created_at', '<=', now())
            ->selectRaw('date(created_at at time zone ?) as day, count(*) as total', [config('dcms.business_timezone')])
            ->groupBy('day')->pluck('total', 'day');
        $days = collect(range(6, 0))->map(function (int $offset) use ($today, $counts): array {
            $date = $today->copy()->subDays($offset);

            return ['label' => $date->format('D'), 'date' => $date->format('d M'), 'total' => (int) ($counts[$date->toDateString()] ?? 0)];
        });

        return [
            'total' => (int) $totals->total, 'active' => (int) $totals->active, 'inactive' => (int) $totals->inactive,
            'clients' => $this->access->clients($user)->count(), 'days' => $days, 'weekTotal' => $days->sum('total'),
            'recent' => (clone $customers)->with('client:id,name')->orderByDesc('id')->limit(5)->get(['id', 'public_id', 'client_id', 'customer_reference', 'display_name_ciphertext', 'status', 'party_type', 'created_at']),
            'activity' => AuditLog::query()->where('module', 'M13')->where('entity_type', 'debtor')
                ->whereIn('entity_id', (clone $customers)->selectRaw('public_id::text'))
                ->with('actor:id,name')->orderByDesc('id')->limit(5)->get(),
        ];
    }
}
