<?php

namespace App\Livewire\Admin\Accounts;

use App\Actions\Accounts\SaveDebtAccount;
use App\Models\DebtAccount;
use App\Models\Debtor;
use App\Models\ReferenceValue;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.admin')]
class Editor extends Component
{
    #[Locked]
    public int $customerId;

    #[Locked]
    public ?int $accountId = null;

    #[Locked]
    public ?string $revision = null;

    public array $data = ['account_number' => '', 'billing_reference' => '', 'currency' => 'MYR', 'status' => 'active', 'role_code' => '', 'address' => '', 'building' => '', 'postcode' => '', 'state_code' => ''];

    public function mount(Debtor $customer, ?DebtAccount $account = null): void
    {
        abort_unless(Auth::user()->isActive(), 404);
        Gate::authorize('view', $customer);
        $this->customerId = $customer->id;
        if ($account) {
            abort_unless($account->parties()->where('debtor_id', $customer->id)->exists(), 404);
            Gate::authorize('update', $account);
            $this->accountId = $account->id;
            $this->revision = $account->revision();
            $party = $account->parties()->where('debtor_id', $customer->id)->whereNull('valid_to')->whereNull('source_slot')->first();
            $this->data = ['account_number' => $account->account_number, 'billing_reference' => $account->billing_reference ?? '', 'currency' => $account->currency, 'status' => $account->status, 'role_code' => $party?->role_code ?? '', 'address' => $account->property?->address_lines_ciphertext ?? '', 'building' => $account->property?->building ?? '', 'postcode' => $account->property?->postcode ?? '', 'state_code' => $account->property?->state_code ?? ''];
        } else {
            Gate::authorize('create', DebtAccount::class);
        }
    }

    public function save(SaveDebtAccount $save): void
    {
        $customer = Debtor::query()->findOrFail($this->customerId);
        $account = $save->handle(Auth::user(), $customer, $this->data, $this->accountId ? DebtAccount::query()->findOrFail($this->accountId) : null, $this->revision);
        session()->flash('status', 'Account and customer relationship saved.');
        $this->redirectRoute('admin.customers.show', ['customer' => $customer, 'tab' => 'accounts']);
    }

    public function render(): View
    {
        $customer = Debtor::query()->findOrFail($this->customerId);
        abort_unless(Auth::user()->isActive(), 404);
        Gate::authorize('view', $customer);
        if ($this->accountId) {
            Gate::authorize('update', DebtAccount::query()->findOrFail($this->accountId));
        } else {
            Gate::authorize('create', DebtAccount::class);
        }

        return view('livewire.admin.accounts.editor', ['customer' => $customer, 'roles' => ReferenceValue::query()->where('category', 'party_role')->where('is_active', true)->get(), 'states' => ReferenceValue::query()->where('category', 'state')->where('is_active', true)->get()]);
    }
}
