<?php

namespace App\Livewire\Admin\Tasks;

use App\Actions\Tasks\TaskOperation;
use App\Models\AssignmentBatch;
use App\Models\BulkOperation;
use App\Models\Task;
use App\Services\AuditWriter;
use App\Services\Tasks\TaskAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('layouts.admin')]
class Allocation extends Component
{
    public string $batchId = '';

    public string $operation = 'new';

    public string $mode = 'selected';

    public string $riderId = '';

    public string $reason = '';

    public string $fromIwk = '';

    public string $toIwk = '';

    public array $selected = [];

    #[Locked]
    public ?string $operationId = null;

    #[Locked]
    public string $digest = '';

    public function mount(?Task $task = null, ?BulkOperation $operation = null): void
    {
        Gate::authorize('viewAny', Task::class);
        if ($operation) {
            $this->operationId = $operation->public_id;

            return;
        }
        $selected = $task ? [$task->public_id] : session('task_selection.'.Auth::id(), []);
        $this->selected = array_values(array_filter($selected, fn ($id) => is_string($id) && Str::isUuid($id)));
        if ($task) {
            $this->mode = 'single';
        }
        $tasks = Task::query()->whereIn('public_id', $this->selected)->with('batch')->get();
        if ($tasks->count() !== count($this->selected)) {
            abort(404);
        }
        if ($tasks->pluck('assignment_batch_id')->unique()->count() === 1) {
            $this->batchId = (string) $tasks->first()->assignment_batch_id;
        }
    }

    private function operationRecord(): ?BulkOperation
    {
        if (! $this->operationId) {
            return null;
        }
        $record = BulkOperation::query()->with(['batch', 'collector'])->where('public_id', $this->operationId)->firstOrFail();
        abort_unless(Auth::user()->isActive(), 404);
        Gate::authorize('viewAny', Task::class);

        return $record;
    }

    public function preview(TaskOperation $actions): void
    {
        $tasks = Task::query()->whereIn('public_id', array_filter($this->selected, fn ($id) => is_string($id) && Str::isUuid($id)))->get();
        if (count($this->selected) !== $tasks->count()) {
            abort(404);
        }
        $operation = $actions->prepare(Auth::user(), ['assignment_batch_id' => $this->batchId, 'operation_code' => $this->operation, 'selection_mode' => $this->mode, 'task_ids' => $tasks->pluck('id')->all(), 'target_collector_id' => $this->riderId ?: null, 'reason' => $this->reason, 'from_iwk_id' => $this->fromIwk, 'to_iwk_id' => $this->toIwk]);
        $this->operationId = $operation->public_id;
        $this->digest = $operation->preview_digest;
        $this->redirectRoute('admin.tasks.operation', ['operation' => $operation]);
    }

    public function confirm(TaskOperation $actions): void
    {
        $record = $this->operationRecord();
        abort_unless($record, 404);
        $actions->confirm(Auth::user(), $record, $this->digest);
        session()->flash('status', 'Operation queued. Processing starts when the default queue worker receives it.');
    }

    public function cancel(TaskOperation $actions): void
    {
        $record = $this->operationRecord();
        abort_unless($record, 404);
        $actions->cancel(Auth::user(), $record);
        session()->flash('status', 'Outstanding items cancelled. Applied records and history remain.');
    }

    public function downloadResults(): StreamedResponse
    {
        $record = $this->operationRecord();
        abort_unless($record, 404);
        app(AuditWriter::class)->record(Auth::user(), 'task_operation_results_downloaded', $record->public_id, module: 'M05', entityType: 'bulk_operation');

        return response()->streamDownload(function () use ($record): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Task public ID', 'State', 'Result']);
            foreach ($record->items()->with('task')->lazyById(100) as $item) {
                fputcsv($out, [$item->task->public_id, $item->state, $item->result_code]);
            } fclose($out);
        }, 'task-operation-'.$record->public_id.'.csv', ['Content-Type' => 'text/csv', 'Cache-Control' => 'no-store']);
    }

    public function render(TaskAccess $tasks): View
    {
        Gate::authorize('viewAny', Task::class);
        $record = $this->operationRecord();
        if ($record) {
            $this->digest = $record->preview_digest;
        }
        $batches = AssignmentBatch::query()->where('status', 'active')->latest('id')->limit(200)->get();
        $batch = ctype_digit($this->batchId) ? $batches->firstWhere('id', (int) $this->batchId) : null;

        return view('livewire.admin.tasks.allocation', ['record' => $record, 'batches' => $batches, 'riders' => $batch ? $tasks->riders()->orderBy('first_name')->get() : collect(), 'items' => $record ? $record->items()->with(['task.customer', 'task.account', 'task.currentAllocation.collector'])->orderBy('id')->get() : collect(), 'selection' => Task::query()->whereIn('public_id', array_filter($this->selected, fn ($id) => is_string($id) && Str::isUuid($id)))->with(['customer', 'account'])->get()]);
    }
}
