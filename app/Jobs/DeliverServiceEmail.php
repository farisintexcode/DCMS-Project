<?php

namespace App\Jobs;

use App\Actions\Communications\DeliverEmail;
use App\Models\OutboxEvent;
use App\Models\ProcessedEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimitedWithRedis;

/** IDs only; all transport replay decisions are durable business state. */
class DeliverServiceEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $timeout = 60;

    public function __construct(public int $eventId)
    {
        $this->onConnection('redis')->onQueue('email');
    }

    public function middleware(): array
    {
        return [new RateLimitedWithRedis('service-email')];
    }

    public function handle(DeliverEmail $delivery): void
    {
        $event = OutboxEvent::query()->where('event_type', 'email.deliver')->where('aggregate_type', 'communication')->findOrFail($this->eventId);
        if (ProcessedEvent::query()->where('event_key', $event->event_key)->where('consumer', 'm14-email')->exists()) {
            return;
        }
        $delay = $delivery->process($event->aggregate_id);
        if ($delay !== null) {
            $this->release($delay);

            return;
        }
        ProcessedEvent::unguarded(fn () => ProcessedEvent::query()->firstOrCreate(['event_key' => $event->event_key, 'consumer' => 'm14-email'], ['processed_at' => now()]));
    }
}
