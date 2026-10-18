<?php

namespace App\Livewire\Admin\Tasks;

use App\Actions\Tasks\SaveTask;
use App\Models\AssignmentBatch;
use App\Models\DebtAccount;
use App\Models\Debtor;
use App\Models\ReferenceValue;
use App\Models\Task;
use App\Services\ClientAccess;
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
    public ?int $taskId = null;

    #[Locked]
    public ?int $version = null;

    public array $data = ['client_id' => '', 'assignment_batch_id' => '', 'customer_id' => '', 'debt_account_id' => '', 'priority_code' => 'normal', 'scheduled_visit_at' => '', 'due_at' => '', 'instructions' => '', 'admin_remarks' => ''];

    public string $customerSearch = '';

    public function mount(?Task $task = null): void
    {
        Gate::authorize('viewAny', Task::class);
        abort_unless(Auth::user()->can($task ? 'tasks.update' : 'tasks.create'), 403);
        if ($task) {
            abort_unless(Auth::user()->hasClientScope($task->batch->client), 404);
            Gate::authorize('update', $task);
            $this->taskId = $task->id;
            $this->version = $task->lock_version;
            $this->data = ['client_id' => (string) $task->batch->client_id, 'assignment_batch_id' => (string) $task->assignment_batch_id, 'customer_id' => (string) $task->customer_id, 'debt_account_id' => (string) $task->debt_account_id, 'priority_code' => $task->priority_code, 'scheduled_visit_at' => $task->scheduled_visit_at?->timezone(config('dcms.business_timezone'))->format('Y-m-d\TH:i') ?? '', 'due_at' => $task->due_at?->timezone(config('dcms.business_timezone'))->format('Y-m-d\TH:i') ?? '', 'instructions' => $task->special_instruction_ciphertext ?? '', 'admin_remarks' => $task->admin_remarks_ciphertext ?? ''];
        }
    }

    public function updatedDataClientId(): void
    {
        if (! $this->taskId) {
            $this->data['customer_id'] = '';
            $this->data['debt_account_id'] = '';
            $this->data['assignment_batch_id'] = '';
        }
    }

    public function updatedDataCustomerId(): void
    {
        if (! $this->taskId) {
            $this->data['debt_account_id'] = '';
        }
    }

    public function save(SaveTask $save): void
    {
        $task = $save->handle(Auth::user(), $this->data, $this->taskId ? Task::query()->findOrFail($this->taskId) : null, $this->version);
        session()->flash('status', 'Task saved with its source and history preserved.');
        $this->redirectRoute('admin.tasks.show', ['task' => $task]);
    }

    public function render(ClientAccess $access): View
    {
        Gate::authorize('viewAny', Task::class);
        $task = $this->taskId ? Task::query()->with('batch.client')->findOrFail($this->taskId) : null;
        if ($task) {
            abort_unless(Auth::user()->hasClientScope($task->batch->client), 404);
            Gate::authorize('update', $task);
        }
        $client = ctype_digit((string) $this->data['client_id']) ? $access->clients(Auth::user())->find($this->data['client_id']) : null;
        $customers = Debtor::query()->where('client_id', $client?->id ?? 0)->when(! $task, fn ($q) => $q->where('status', 'active'))->when($this->customerSearch !== '', fn ($q) => $q->where('customer_reference', trim($this->customerSearch)))->when($task, fn ($q) => $q->where('id', $task->customer_id))->orderBy('customer_reference')->limit(100)->get();

        return view('livewire.admin.tasks.editor', ['task' => $task, 'clients' => $access->clients(Auth::user())->get(), 'batches' => AssignmentBatch::query()->where('client_id', $client?->id ?? 0)->where(fn ($q) => $q->where('status', 'active')->when($task, fn ($q) => $q->orWhere('id', $task->assignment_batch_id)))->latest('id')->limit(200)->get(), 'customers' => $customers, 'accounts' => DebtAccount::query()->where('client_id', $client?->id ?? 0)->whereHas('parties', fn ($q) => $q->where('debtor_id', ctype_digit((string) $this->data['customer_id']) ? $this->data['customer_id'] : 0)->where('valid_from', '<=', now()->format('Y-m-d H:i:s.uP'))->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', now()->format('Y-m-d H:i:s.uP'))))->get(), 'priorities' => ReferenceValue::query()->where('category', 'priority')->where('is_active', true)->get()]);
    }
}
