<?php

namespace App\Livewire\Admin\Calls;

use App\Actions\Calls\RecordCall;
use App\Actions\Customers\SaveCustomerContacts;
use App\Models\AccountParty;
use App\Models\Debtor;
use App\Models\ReferenceValue;
use App\Models\Task;
use App\Services\Calls\CallAccess;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/** M11/UI60/UI64 call/visit slice: explicit manual logs and controlled contact edits. */
#[Layout('layouts.admin')]
class Show extends Component
{
    use WithPagination;

    #[Locked]
    public int $customerId;

    #[Locked]
    public string $requestId;

    #[Locked]
    public string $revision = '';

    public array $form = [];

    public array $data = ['contacts' => []];

    public bool $editingContacts = false;

    public function mount(Debtor $customer, CallAccess $access): void
    {
        $access->customer(auth()->user(), $customer->id);
        $this->customerId = $customer->id;
        $this->requestId = (string) Str::uuid7();
        $this->resetCall();
        $task = request()->query('task', '');
        if (is_string($task) && Str::isUuid($task) && Gate::allows('viewAny', Task::class) && Task::query()->where('customer_id', $customer->id)->where('public_id', $task)->exists()) {
            $this->form['task'] = $task;
        }
    }

    private function customer(): Debtor
    {
        return app(CallAccess::class)->customer(auth()->user(), $this->customerId);
    }

    private function resetCall(): void
    {
        $this->form = ['task' => '', 'contact' => '', 'outcome' => '', 'historical' => false, 'occurred_at' => now()->timezone(config('dcms.business_timezone'))->format('Y-m-d\\TH:i'), 'backdating_reason' => '', 'person' => '', 'remarks' => '', 'other_detail' => '', 'promise_notes' => '', 'promised_date' => '', 'promised_amount' => '', 'currency' => '', 'follow_up_at' => '', 'follow_up_notes' => ''];
    }

    public function saveCall(RecordCall $record): void
    {
        $call = $record->handle(auth()->user(), $this->customer(), $this->form, $this->requestId);
        $this->requestId = (string) Str::uuid7();
        $this->resetCall();
        $this->resetPage('historyPage');
        session()->flash('status', 'Outgoing call recorded · '.$call->public_id.'. This is a manual log; no automatic call or message was sent.');
    }

    public function editContacts(): void
    {
        $customer = $this->customer();
        Gate::authorize('updateContacts', $customer);
        $this->revision = $customer->revision();
        $this->data['contacts'] = $customer->contacts()->whereNull('valid_to')->orderBy('id')->get()->map(fn ($row) => ['kind' => $row->kind_code, 'value' => $row->value_ciphertext, 'is_primary' => $row->is_primary])->all();
        $this->editingContacts = true;
    }

    public function addContact(): void
    {
        Gate::authorize('updateContacts', $this->customer());
        if (count($this->data['contacts']) < 20) {
            $this->data['contacts'][] = ['kind' => 'mobile', 'value' => '', 'is_primary' => false];
        }
    }

    public function removeContact(int $index): void
    {
        Gate::authorize('updateContacts', $this->customer());
        unset($this->data['contacts'][$index]);
        $this->data['contacts'] = array_values($this->data['contacts']);
    }

    public function saveContacts(SaveCustomerContacts $save): void
    {
        $customer = $save->handle(auth()->user(), $this->customer(), $this->data['contacts'], $this->revision);
        $this->revision = $customer->revision();
        $this->editingContacts = false;
        $this->data = ['contacts' => []];
        session()->flash('status', 'Contact observations saved. Previous values remain in customer history.');
    }

    public function cancelContacts(): void
    {
        $this->data = ['contacts' => []];
        $this->editingContacts = false;
    }

    public function render(CallAccess $access): View
    {
        $customer = $this->customer();
        $canTasks = Gate::allows('viewAny', Task::class);
        $tasks = $canTasks ? Task::query()->where('customer_id', $customer->id)->with(['batch', 'account', 'currentAllocation.collector'])->orderByDesc('id')->limit(100)->get() : collect();

        return view('livewire.admin.calls.show', [
            'customer' => $customer, 'canTasks' => $canTasks, 'tasks' => $tasks,
            'accounts' => AccountParty::query()->where('debtor_id', $customer->id)->whereNull('valid_to')->with('account')->orderByDesc('id')->limit(50)->get(),
            'contacts' => $customer->contacts()->whereNull('valid_to')->whereIn('kind_code', ['telephone', 'mobile'])->orderByDesc('is_primary')->orderBy('id')->get(),
            'outcomes' => ReferenceValue::query()->where('category', 'activity_outcome')->where('is_active', true)->whereJsonContains('metadata->activity_types', 'call')->orderBy('sort_order')->orderBy('id')->get(),
            'history' => $access->activities(auth()->user(), $customer)->with(['performer', 'followUps.assignee', 'followUps.resolver'])->orderByDesc('occurred_at')->orderByDesc('id')->paginate(15, ['*'], 'historyPage'),
        ]);
    }
}
