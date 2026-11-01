<?php

namespace App\Actions\Customers;

use App\Models\CustomerCategory;
use App\Models\CustomerCategoryAssignment;
use App\Models\Debtor;
use App\Models\User;
use App\Services\AuditWriter;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Called inside a transaction with the customer locked. M13/UI61. */
class SyncCustomerCategories
{
    public function __construct(private AuditWriter $audit) {}

    public function handle(User $actor, Debtor $customer, array $ids): void
    {
        Gate::forUser($actor)->authorize('categorize', $customer);
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $current = $customer->categoryAssignments()->whereNull('revoked_at')->pluck('category_id')->all();
        $categories = CustomerCategory::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        if ($categories->count() !== count($ids) || $categories->contains(fn ($c) => (! $c->is_active && ! in_array($c->id, $current)))) {
            throw ValidationException::withMessages(['data.category_ids' => 'Choose active categories. Existing inactive memberships may be retained.']);
        }
        $now = now();
        $instant = $now->format('Y-m-d H:i:s.uP');
        $customer->categoryAssignments()->whereNull('revoked_at')->whereNotIn('category_id', $ids)->update(['revoked_at' => $instant, 'updated_at' => $instant]);
        foreach (array_diff($ids, $current) as $id) {
            $membership = new CustomerCategoryAssignment;
            $membership->customer_id = $customer->id;
            $membership->category_id = $id;
            $membership->assigned_by = $actor->id;
            $membership->assigned_at = $now;
            $membership->save();
        }
        if (array_diff($ids, $current) || array_diff($current, $ids)) {
            $this->audit->record($actor, 'customer_categories_changed', $customer->public_id, ['category_ids' => $ids], module: 'M13', entityType: 'debtor', previous: ['category_ids' => $current]);
        }
    }
}
