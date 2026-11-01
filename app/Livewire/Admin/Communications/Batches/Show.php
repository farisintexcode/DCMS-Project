<?php

namespace App\Livewire\Admin\Communications\Batches;

use App\Actions\Communications\DeliverEmail;
use App\Actions\Communications\PreviewRecipients;
use App\Models\CommunicationBatch;
use App\Services\Communications\EmailCapacity;
use App\Services\Communications\RecipientAccess;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin')]
class Show extends Component
{
    use WithPagination;

    #[Locked]
    public int $batchId;

    #[Locked]
    public array $freshness = [];

    #[Locked]
    public ?string $historyRecipient = null;

    public function inspectHistory(string $recipient): void
    {
        abort_unless(Str::isUuid($recipient), 404);
        $this->batch()->communications()->where('public_id', $recipient)->firstOrFail();
        $this->historyRecipient = $recipient;
        $this->resetPage('attemptPage');
        $this->resetPage('eventPage');
    }

    public function mount(CommunicationBatch $batch): void
    {
        $this->batchId = $batch->id;
        $this->batch();
    }

    private function batch(): CommunicationBatch
    {
        $batch = app(RecipientAccess::class)->batches(auth()->user())->with(['template', 'requester'])->findOrFail($this->batchId);
        Gate::authorize('view', $batch);

        return $batch;
    }

    public function recheck(PreviewRecipients $preview): void
    {
        $this->freshness = [...$preview->freshness(auth()->user(), $this->batch()), 'checked_at' => now()->timezone(config('dcms.business_timezone'))->format('d M Y H:i:s')];
    }

    public function cancel(int $version, PreviewRecipients $preview): void
    {
        $batch = $this->batch();
        if ($batch->confirmed_at) {
            app(DeliverEmail::class)->cancel(auth()->user(), $batch, $version);
        } else {
            $preview->cancel(auth()->user(), $batch, $version);
        }
        session()->flash('status', 'Pending work cancelled. Recipient and attempt history is retained.');
    }

    public function confirm(int $version, string $digest, DeliverEmail $delivery): void
    {
        $delivery->confirm(auth()->user(), $this->batch(), $version, $digest);
        session()->flash('status', 'Exact eligible recipient snapshot confirmed and queued. No delivery is implied.');
    }

    public function resume(int $version, DeliverEmail $delivery): void
    {
        $delivery->resume(auth()->user(), $this->batch(), $version);
        session()->flash('status', 'Pending work resumed. Current authority and capacity will be rechecked.');
    }

    public function render(): View
    {
        $batch = $this->batch();

        $capacity = app(EmailCapacity::class);
        $account = $capacity->account();

        $history = $this->historyRecipient ? $batch->communications()->where('public_id', $this->historyRecipient)->firstOrFail() : null;

        return view('livewire.admin.communications.batches.show', ['batch' => $batch, 'providerReady' => $account !== null, 'capacity' => $account ? $capacity->remaining($account) : null, 'history' => $history, 'historyAttempts' => $history?->attempts()->orderByDesc('id')->paginate(10, ['*'], 'attemptPage'), 'historyEvents' => $history?->events()->orderByDesc('id')->paginate(10, ['*'], 'eventPage'), 'liveCounts' => $batch->communications()->selectRaw('delivery_status, count(*) AS total')->groupBy('delivery_status')->pluck('total', 'delivery_status'), 'messages' => $batch->communications()->with('customer')->withCount('attempts')->orderBy('id')->paginate(10)]);
    }
}
