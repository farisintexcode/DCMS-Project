<?php

namespace App\Livewire\Admin\Customers;

use App\Actions\Customers\SaveCategory;
use App\Actions\Customers\SyncCustomerCategories;
use App\Models\Client;
use App\Models\CustomerCategory;
use App\Models\Debtor;
use App\Services\Customers\CustomerAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/** UI61 category administration. Customer membership editor is shared on UI24/UI63. */
#[Layout('layouts.admin')]
class Categories extends Component
{
    use WithPagination;

    #[Locked]
    public ?int $categoryId = null;

    #[Locked]
    public ?string $revision = null;

    public string $client = '';

    public string $status = '';

    public string $query = '';

    public array $data = ['client_id' => '', 'code' => '', 'name' => '', 'description' => '', 'is_active' => true];

    #[Locked]
    public ?int $membershipCategoryId = null;

    #[Locked]
    public array $selectionVersions = [];

    public array $selectedCustomers = [];

    public string $memberReference = '';

    public string $membershipOperation = 'assign';

    public function members(string $publicId): void
    {
        abort_unless(Auth::user()->can('customers.categorize') && Auth::user()->can('customers.view'), 403);
        $category = app(CustomerAccess::class)->scope(CustomerCategory::query(), Auth::user())->where('public_id', $publicId)->firstOrFail();
        $this->membershipCategoryId = $category->id;
        $this->reset('selectedCustomers', 'selectionVersions', 'memberReference');
    }

    public function updatedSelectedCustomers(): void
    {
        $this->validate(['selectedCustomers' => ['array', 'max:50'], 'selectedCustomers.*' => ['integer', 'distinct']]);
        $category = app(CustomerAccess::class)->scope(CustomerCategory::query(), Auth::user())->findOrFail($this->membershipCategoryId);
        $customers = Debtor::query()->where('client_id', $category->client_id)->whereIn('id', $this->selectedCustomers)->get();
        abort_unless($customers->count() === count($this->selectedCustomers), 404);
        $versions = [];
        foreach ($customers as $customer) {
            $versions[$customer->id] = $this->selectionVersions[$customer->id] ?? $customer->revision();
        }
        $this->selectionVersions = $versions;
    }

    public function applyMemberships(SyncCustomerCategories $sync): void
    {
        abort_unless(Auth::user()->can('customers.categorize') && Auth::user()->can('customers.view'), 403);
        $this->validate(['selectedCustomers' => ['required', 'array', 'min:1', 'max:50'], 'selectedCustomers.*' => ['integer', 'distinct'], 'membershipOperation' => ['required', 'in:assign,revoke']]);
        DB::transaction(function () use ($sync): void {
            $category = app(CustomerAccess::class)->scope(CustomerCategory::query(), Auth::user())->findOrFail($this->membershipCategoryId);
            Client::query()->lockForUpdate()->findOrFail($category->client_id);
            $customers = Debtor::query()->with('client')->where('client_id', $category->client_id)->whereIn('id', $this->selectedCustomers)->orderBy('id')->lockForUpdate()->get();
            abort_unless($customers->count() === count($this->selectedCustomers), 404);
            foreach ($customers as $customer) {
                Gate::authorize('categorize', $customer);
                if (! isset($this->selectionVersions[$customer->id]) || ! hash_equals($customer->revision(), $this->selectionVersions[$customer->id])) {
                    throw ValidationException::withMessages(['selectedCustomers' => 'A selected customer changed. Reselect the customers and review before applying.']);
                }
                $ids = $customer->categoryAssignments()->whereNull('revoked_at')->pluck('category_id')->all();
                $ids = $this->membershipOperation === 'assign' ? array_unique([...$ids, $category->id]) : array_diff($ids, [$category->id]);
                $sync->handle(Auth::user(), $customer, $ids);
            }
        }, 3);
        $count = count($this->selectedCustomers);
        $this->reset('selectedCustomers', 'selectionVersions');
        session()->flash('status', "Category operation applied to {$count} explicitly selected customers.");
    }

    public function edit(string $publicId): void
    {
        $category = app(CustomerAccess::class)->scope(CustomerCategory::query(), Auth::user())->where('public_id', $publicId)->with('client')->firstOrFail();
        Gate::authorize('update', $category);
        $this->categoryId = $category->id;
        $this->revision = $category->revision();
        $this->data = $category->only('client_id', 'code', 'name', 'description', 'is_active');
    }

    public function cancel(): void
    {
        $this->reset('categoryId', 'revision', 'data');
        $this->resetValidation();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['client', 'status', 'query'])) {
            $this->resetPage();
        }
    }

    public function save(SaveCategory $save): void
    {
        $category = $this->categoryId ? CustomerCategory::query()->findOrFail($this->categoryId) : null;
        $save->handle(Auth::user(), $this->data, $category, $this->revision);
        $this->cancel();
        session()->flash('status', 'Category saved. Existing membership history is retained.');
    }

    public function render(): View
    {
        abort_unless(Auth::user()->isActive() && Auth::user()->can('customers.manage_categories'), 403);
        if ($this->membershipCategoryId) {
            abort_unless(Auth::user()->can('customers.view') && Auth::user()->can('customers.categorize'), 403);
        }
        $access = app(CustomerAccess::class);
        $query = $access->scope(CustomerCategory::query(), Auth::user())->with('client')->withCount(['categoryAssignments as current_members' => fn ($q) => $q->whereNull('revoked_at')]);
        if ($this->client !== '') {
            $query->where('client_id', (int) $this->client);
        }
        if (in_array($this->status, ['active', 'inactive'])) {
            $query->where('is_active', $this->status === 'active');
        }
        if ($this->query !== '') {
            $query->where(fn ($q) => $q->where('code', 'ilike', '%'.mb_substr($this->query, 0, 100).'%')->orWhere('name', 'ilike', '%'.mb_substr($this->query, 0, 100).'%'));
        }

        $membershipCategory = $this->membershipCategoryId ? $access->scope(CustomerCategory::query(), Auth::user())->findOrFail($this->membershipCategoryId) : null;
        $members = $membershipCategory ? Debtor::query()->where('client_id', $membershipCategory->client_id)->when($this->memberReference !== '', fn ($q) => $q->where('customer_reference', trim($this->memberReference)))->orderBy('id')->limit(50)->get() : collect();

        return view('livewire.admin.customers.categories', ['categories' => $query->orderBy('name')->orderBy('id')->paginate(25), 'clients' => $access->clients(Auth::user())->orderBy('name')->get(), 'membershipCategory' => $membershipCategory, 'memberCustomers' => $members]);
    }
}
