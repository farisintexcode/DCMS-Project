<?php

namespace App\Livewire\Admin\Assignments;

use App\Actions\Assignments\ChangeBatchStatus;
use App\Actions\Assignments\SaveAssignmentBatch;
use App\Enums\BatchStatus;
use App\Models\AssignmentBatch;
use App\Models\AuditLog;
use App\Models\ReferenceValue;
use App\Services\Assignments\BatchWork;
use App\Services\ClientAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** UI11/12; archived assignments are read-only, transitions are a separate audited action. */
#[Layout('layouts.admin')]
class Editor extends Component
{
    #[Locked]
    public ?int $batchId = null;

    #[Locked]
    public ?int $version = null;

    public array $data = ['client_id' => '', 'name' => '', 'external_reference' => '', 'type_code' => '', 'start_date' => '', 'due_date' => '', 'description' => '', 'dca_code' => '', 'dca_name' => '', 'la_name' => '', 'state_code' => ''];

    public string $reason = '';

    public function mount(?AssignmentBatch $batch = null): void
    {
        if (! $batch?->exists) {
            Gate::authorize('create', AssignmentBatch::class);

            return;
        }
        abort_unless(Auth::user()->hasClientScope($batch->client), 404);
        Gate::authorize('view', $batch);
        request()->attributes->set('assignment_status', $batch->status->value);
        $this->batchId = $batch->id;
        $this->version = $batch->lock_version;
        foreach (array_keys($this->data) as $field) {
            $this->data[$field] = in_array($field, ['start_date', 'due_date']) ? $batch->{$field}->format('Y-m-d') : ($batch->{$field} ?? '');
        }
    }

    public function save(SaveAssignmentBatch $save): void
    {
        $batch = $save->handle(Auth::user(), $this->data, $this->batchId ? AssignmentBatch::query()->findOrFail($this->batchId) : null, $this->version);
        session()->flash('status', 'Assignment saved. No tasks were uploaded or allocated.');
        $this->redirectRoute('admin.assignments.edit', ['batch' => $batch->public_id]);
    }

    public function changeStatus(string $target, ChangeBatchStatus $change): void
    {
        abort_unless($this->batchId !== null, 404);
        $batch = $change->handle(Auth::user(), AssignmentBatch::query()->findOrFail($this->batchId), $target, $this->reason, $this->version ?? -1);
        $this->version = $batch->lock_version;
        $this->reason = '';
        session()->flash('status', $batch->status === BatchStatus::Archived ? 'Assignment archived. Metadata is now read-only; all history is retained.' : 'Assignment reopened under authorization.');
        $this->redirectRoute('admin.assignments.edit', ['batch' => $batch->public_id]);
    }

    public function render(ClientAccess $access, BatchWork $work): View
    {
        $batch = $this->batchId ? AssignmentBatch::query()->with(['client', 'creator', 'type', 'state'])->findOrFail($this->batchId) : null;
        if ($batch) {
            abort_unless(Auth::user()->hasClientScope($batch->client), 404);
            Gate::authorize('view', $batch);
        } else {
            Gate::authorize('create', AssignmentBatch::class);
        }
        $references = fn (string $category, ?string $current) => ReferenceValue::query()->where('category', $category)->where(fn ($q) => $q->where('is_active', true)->when($current, fn ($q) => $q->orWhere('code', $current)))->orderBy('label')->get();

        return view('livewire.admin.assignments.editor', [
            'batch' => $batch, 'clients' => $access->clients(Auth::user())->orderBy('name')->get(),
            'types' => $references('assignment_type', $batch?->type_code), 'states' => $references('state', $batch?->state_code),
            'readOnly' => $batch && ($batch->status === BatchStatus::Archived || ! Gate::allows('update', $batch)),
            'work' => $batch ? $work->summary($batch) : null,
            'activity' => $batch ? AuditLog::query()->where('module', 'M03')->where('entity_type', 'assignment_batch')->where('entity_id', $batch->public_id)->with('actor:id,name')->orderByDesc('id')->limit(10)->get() : collect(),
            'backFilters' => session('assignment_filters.'.Auth::id(), []),
        ]);
    }
}
