<?php

namespace App\Services\Calls;

use App\Models\AccountParty;
use App\Models\CollectionActivity;
use App\Models\Debtor;
use App\Models\Task;
use App\Models\User;
use App\Services\ClientAccess;
use App\Services\Customers\CustomerSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/** M11/UI60: current permissions and portfolio scope apply to every query. */
class CallAccess
{
    public function __construct(private ClientAccess $clients, private CustomerSearch $search) {}

    public function allowed(User $actor): bool
    {
        return $actor->isActive() && $actor->can('admin.access') && $actor->can('calls.record') && $actor->can('customers.view');
    }

    public function customers(User $actor): Builder
    {
        return $this->clients->scope(Debtor::query(), $actor);
    }

    public function customer(User $actor, int $id): Debtor
    {
        abort_unless($this->allowed($actor), 403);

        return $this->customers($actor)->with('client')->findOrFail($id);
    }

    public function activities(User $actor, Debtor $customer): Builder
    {
        $query = CollectionActivity::query()->where('customer_id', $customer->id);

        return $query->where(function ($q) use ($actor): void {
            $q->where('activity_type_code', 'call');
            if (Gate::forUser($actor)->allows('viewAny', Task::class)) {
                $q->orWhere(fn ($q) => $q->where('activity_type_code', 'visit')->whereHas('task.batch', fn ($q) => $this->clients->scope($q, $actor)));
            }
        });
    }

    public function search(User $actor, array $filters): Builder
    {
        $q = $this->customers($actor);
        foreach (['client', 'category', 'rider'] as $field) {
            $value = $filters[$field] ?? '';
            if ($value === '') {
                continue;
            }
            if (! Str::isUuid($value)) {
                $q->whereRaw('false');

                continue;
            }
            if ($field === 'client') {
                $q->whereHas('client', fn ($b) => $b->where('public_id', $value));
            } elseif ($field === 'category') {
                $q->whereHas('categoryAssignments', fn ($b) => $b->whereNull('revoked_at')->whereHas('category', fn ($b) => $b->where('public_id', $value)));
            } elseif (Gate::forUser($actor)->allows('viewAny', Task::class)) {
                $q->whereIn('id', Task::query()->select('customer_id')->whereHas('currentAllocation.collector', fn ($b) => $b->where('public_id', $value)));
            } else {
                $q->whereRaw('false');
            }
        }
        $term = trim($filters['query'] ?? '');
        $kind = $filters['searchBy'] ?? 'reference';
        if (! in_array($kind, ['reference', 'name', 'contact', 'account', 'task'], true)) {
            return $q->whereRaw('false');
        }
        if (mb_strlen($term) > 200) {
            return $q->whereRaw('false');
        }
        if ($term === '') {
            return $q;
        }
        if ($kind === 'account') {
            $q->whereIn('id', AccountParty::query()->select('debtor_id')->whereNull('valid_to')->whereHas('account', fn ($b) => $b->where('account_number', $term)));
        } elseif ($kind === 'task') {
            if (Str::isUuid($term) && Gate::forUser($actor)->allows('viewAny', Task::class)) {
                $q->whereIn('id', Task::query()->select('customer_id')->where('public_id', $term));
            } else {
                $q->whereRaw('false');
            }
        } else {
            $this->search->apply($q, $kind, $term);
        }

        return $q;
    }
}
