<?php

namespace App\Livewire\Admin\Customers;

use App\Actions\Customers\SyncCustomerCategories;
use App\Models\AccountParty;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\CollectionActivity;
use App\Models\CustomerCategory;
use App\Models\Debtor;
use App\Models\Task;
use App\Services\Calls\CallAccess;
use App\Services\Tasks\TaskAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin')]
class Show extends Component
{
    use WithPagination;

    #[Locked]
    public int $customerId;

    #[Locked]
    public string $revision;

    #[Url]
    public string $tab = 'overview';

    public array $categoryIds = [];

    public function mount(Debtor $customer): void
    {
        abort_unless(Auth::user()->hasClientScope($customer->client), 404);
        Gate::authorize('view', $customer);
        $this->customerId = $customer->id;
        $this->revision = $customer->revision();
        $this->categoryIds = $customer->categoryAssignments()->whereNull('revoked_at')->pluck('category_id')->all();
    }

    public function saveCategories(SyncCustomerCategories $sync): void
    {
        $this->validate(['categoryIds' => ['array', 'max:100'], 'categoryIds.*' => ['integer', 'distinct']]);
        DB::transaction(function () use ($sync): void {
            $clientId = Debtor::query()->findOrFail($this->customerId)->client_id;
            Client::query()->lockForUpdate()->findOrFail($clientId);
            $customer = Debtor::query()->with('client')->lockForUpdate()->findOrFail($this->customerId);
            abort_unless(Auth::user()->hasClientScope($customer->client), 404);
            Gate::authorize('categorize', $customer);
            if (! hash_equals($customer->revision(), $this->revision)) {
                throw ValidationException::withMessages(['revision' => 'This customer changed. Reload before changing categories.']);
            }
            $sync->handle(Auth::user(), $customer, $this->categoryIds);
            $this->revision = $customer->revision();
        }, 3);
        session()->flash('status', 'Customer categories saved.');
    }

    public function render(): View
    {
        $customer = Debtor::query()->with('client')->findOrFail($this->customerId);
        abort_unless(Auth::user()->hasClientScope($customer->client), 404);
        Gate::authorize('view', $customer);
        $tab = in_array($this->tab, ['overview', 'accounts', 'tasks', 'visits', 'communication', 'history']) ? $this->tab : 'overview';
        if ($tab === 'overview') {
            $customer->load([
                'contacts' => fn ($q) => $q->whereNull('valid_to')->orderByDesc('id'),
                'addresses' => fn ($q) => $q->whereNull('valid_to')->orderByDesc('id'),
                'categoryAssignments' => fn ($q) => $q->whereNull('revoked_at')->with(['category', 'assignedBy'])->orderByDesc('id'),
            ]);
        }

        return view('livewire.admin.customers.show', [
            'conversationHistory' => $tab === 'communication' && app(CallAccess::class)->allowed(Auth::user()) ? app(CallAccess::class)->activities(Auth::user(), $customer)->with(['performer', 'followUps.assignee', 'followUps.resolver'])->orderByDesc('occurred_at')->orderByDesc('id')->paginate(15, ['*'], 'conversationPage') : null,
            'visits' => $tab === 'visits' && Gate::allows('viewAny', Task::class) ? CollectionActivity::query()->where('customer_id', $customer->id)->where('activity_type_code', 'visit')->whereHas('task', fn ($q) => app(TaskAccess::class)->scope($q, Auth::user()))->with(['performer', 'task'])->latest('occurred_at')->orderByDesc('id')->paginate(20, ['*'], 'visitPage') : null,
            'customer' => $customer, 'activeTab' => $tab,
            'accounts' => $tab === 'accounts' ? AccountParty::query()->where('debtor_id', $customer->id)->with(['account.property', 'account.client'])->latest('id')->get() : collect(),
            'categories' => CustomerCategory::query()->where('client_id', $customer->client_id)->orderBy('name')->get(),
            'history' => $tab === 'history' ? AuditLog::query()->where('module', 'M13')->where('entity_type', 'debtor')->where('entity_id', $customer->public_id)->with('actor')->orderByDesc('id')->paginate(25, ['*'], 'auditPage') : null,
            'contactHistory' => $tab === 'history' ? $customer->contacts()->orderByDesc('id')->paginate(25, ['*'], 'contactPage') : null,
            'addressHistory' => $tab === 'history' ? $customer->addresses()->orderByDesc('id')->paginate(25, ['*'], 'addressPage') : null,
            'membershipHistory' => $tab === 'history' ? $customer->categoryAssignments()->with(['category', 'assignedBy'])->orderByDesc('id')->paginate(25, ['*'], 'membershipPage') : null,
        ]);
    }
}
