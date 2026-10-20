<?php

namespace App\Jobs;

use App\Actions\Communications\ApplySesReceipt;
use App\Models\OutboxEvent;
use App\Models\ProcessedEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Durable inbox reducer; no outbound customer message or subscription activation. */
class ProcessSesReceipt implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $eventId)
    {
        $this->onConnection('redis')->onQueue('webhooks');
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(ApplySesReceipt $apply): void
    {
        $event = OutboxEvent::query()->where('event_type', 'ses.receipt')->where('aggregate_type', 'integration_event')->findOrFail($this->eventId);
        if (ProcessedEvent::query()->where('event_key', $event->event_key)->where('consumer', 'ses-receipt')->exists()) {
            return;
        }
        if ($apply->apply($event->aggregate_id)) {
            ProcessedEvent::unguarded(fn () => ProcessedEvent::query()->firstOrCreate(['event_key' => $event->event_key, 'consumer' => 'ses-receipt'], ['processed_at' => now()]));
        }
    }
}
