<?php

namespace App\Livewire\Admin\Communications;

use App\Models\IntegrationEvent;
use App\Models\OutboxEvent;
use App\Services\AuditWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/** UI68/70 operator inbox: safe metadata only; no manual correlation or send override. */
#[Layout('layouts.admin')]
class Receipts extends Component
{
    use WithPagination;

    public string $state = '';

    private function authorizeWorkspace(): void
    {
        abort_unless(auth()->user()->isActive() && auth()->user()->can('admin.access') && auth()->user()->can('communications.view') && auth()->user()->can('integrations.manage'), 403);
    }

    public function updatedState(): void
    {
        $this->resetPage();
    }

    public function retry(string $id, AuditWriter $audit): void
    {
        $this->authorizeWorkspace();
        abort_unless(Str::isUuid($id), 404);
        DB::transaction(function () use ($id, $audit): void {
            $event = IntegrationEvent::query()->where('public_id', $id)->where('state', 'needs_resolution')->where('event_type', 'ses.notification')->lockForUpdate()->firstOrFail();
            OutboxEvent::query()->where('event_type', 'ses.receipt')->where('aggregate_type', 'integration_event')->where('aggregate_id', $event->id)->update(['available_at' => now(), 'dispatched_at' => null]);
            $audit->record(auth()->user(), 'communication.receipt_recheck_requested', $event->public_id, ['status' => 'needs_resolution'], module: 'M14', entityType: 'integration_event');
        });
        session()->flash('status', 'Verified receipt queued for safe rechecking. No recipient mapping or delivery status was overridden.');
    }

    public function render(): View
    {
        $this->authorizeWorkspace();
        $query = IntegrationEvent::query()->select(['id', 'public_id', 'integration_account_id', 'event_type', 'received_at', 'verified_at', 'state', 'resolution_code'])->with('account:id,name');
        if (in_array($this->state, ['pending', 'processed', 'needs_resolution', 'failed'], true)) {
            $query->where('state', $this->state);
        }

        return view('livewire.admin.communications.receipts', ['records' => $query->orderByDesc('id')->paginate(20), 'counts' => IntegrationEvent::query()->selectRaw('state,count(*) AS total')->groupBy('state')->pluck('total', 'state')]);
    }
}
