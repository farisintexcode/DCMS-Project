<?php

namespace App\Livewire\Admin\Customers;

use App\Actions\Customers\SaveCustomer;
use App\Models\CustomerCategory;
use App\Models\Debtor;
use App\Models\ReferenceValue;
use App\Services\ClientAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** UI63; existing identifiers never enter a mutable Livewire property. */
#[Layout('layouts.admin')]
class Editor extends Component
{
    #[Locked]
    public ?int $customerId = null;

    #[Locked]
    public ?string $customerPublicId = null;

    #[Locked]
    public ?string $revision = null;

    public array $data = ['client_id' => '', 'customer_reference' => '', 'party_type' => 'person', 'name' => '', 'identity' => '', 'clear_identity' => false, 'duplicate_confirmed' => false, 'status' => 'active', 'contacts' => [], 'addresses' => [], 'category_ids' => []];

    public function mount(?Debtor $customer = null): void
    {
        if (! $customer?->exists) {
            abort_unless(Auth::user()->can('customers.create'), 403);

            return;
        }
        abort_unless(Auth::user()->hasClientScope($customer->client), 404);
        Gate::authorize('update', $customer);
        $this->customerId = $customer->id;
        $this->customerPublicId = $customer->public_id;
        $this->revision = $customer->revision();
        $this->data = array_replace($this->data, ['client_id' => $customer->client_id, 'customer_reference' => $customer->customer_reference, 'party_type' => $customer->party_type, 'name' => $customer->display_name_ciphertext, 'status' => $customer->status, 'category_ids' => $customer->categoryAssignments()->whereNull('revoked_at')->pluck('category_id')->all()]);
        if (Auth::user()->can('customers.update_contacts')) {
            $this->data['contacts'] = $customer->contacts()->whereNull('valid_to')->orderBy('id')->get()->map(fn ($c) => ['kind' => $c->kind_code, 'value' => $c->value_ciphertext, 'is_primary' => $c->is_primary])->all();
        }
        $this->data['addresses'] = $customer->addresses()->whereNull('valid_to')->orderBy('id')->get()->map(fn ($a) => ['kind' => $a->kind_code, 'recipient' => $a->recipient_name_ciphertext ?? '', 'lines' => $a->lines_ciphertext, 'postcode' => $a->postcode ?? '', 'state_code' => $a->state_code ?? ''])->all();
    }

    public function addContact(): void
    {
        abort_unless(Auth::user()->can('customers.update_contacts'), 403);
        if (count($this->data['contacts']) < 20) {
            $this->data['contacts'][] = ['kind' => 'mobile', 'value' => '', 'is_primary' => false];
        }
    }

    public function updatedDataClientId(): void
    {
        if (! $this->customerId) {
            $this->data['category_ids'] = [];
        }
    }

    public function removeContact(int $index): void
    {
        unset($this->data['contacts'][$index]);
        $this->data['contacts'] = array_values($this->data['contacts']);
    }

    public function addAddress(): void
    {
        if (count($this->data['addresses']) < 10) {
            $this->data['addresses'][] = ['kind' => 'billing', 'recipient' => '', 'lines' => '', 'postcode' => '', 'state_code' => ''];
        }
    }

    public function removeAddress(int $index): void
    {
        unset($this->data['addresses'][$index]);
        $this->data['addresses'] = array_values($this->data['addresses']);
    }

    public function save(SaveCustomer $save): void
    {
        $customer = $this->customerId ? Debtor::query()->with('client')->findOrFail($this->customerId) : null;
        $input = $this->data;
        if (! Auth::user()->can('customers.update_contacts')) {
            unset($input['contacts']);
        }
        if (! Auth::user()->can('customers.categorize')) {
            unset($input['category_ids']);
        }
        $result = $save->handle(Auth::user(), $input, $customer, $this->revision);
        $this->reset('data');
        session()->flash('status', 'Customer saved.');
        $this->redirectRoute('admin.customers.show', ['customer' => $result->public_id]);
    }

    public function render(): View
    {
        abort_unless(Auth::user()->isActive() && Auth::user()->can('customers.view'), 403);
        if (! $this->customerId) {
            abort_unless(Auth::user()->can('customers.create'), 403);
        }
        if ($this->customerId) {
            $customer = Debtor::query()->with('client')->findOrFail($this->customerId);
            abort_unless(Auth::user()->hasClientScope($customer->client), 404);
            Gate::authorize('update', $customer);
        }
        $access = app(ClientAccess::class);
        $categories = $access->scope(CustomerCategory::query(), Auth::user())->where('client_id', (int) ($this->data['client_id'] ?? 0))->orderBy('name')->get();

        return view('livewire.admin.customers.editor', ['clients' => $access->clients(Auth::user())->orderBy('name')->get(), 'categories' => $categories, 'states' => ReferenceValue::query()->where('category', 'state')->where('is_active', true)->orderBy('label')->get()]);
    }
}
