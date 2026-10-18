<?php

namespace App\Jobs;

use App\Actions\Tasks\TaskOperation;
use App\Models\OutboxEvent;
use App\Models\ProcessedEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessTaskOperation implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $eventId)
    {
        $this->onQueue('default');
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(TaskOperation $actions): void
    {
        $event = OutboxEvent::query()->findOrFail($this->eventId);
        if ($event->event_type !== 'tasks.operation' || $event->aggregate_type !== 'bulk_operation' || ProcessedEvent::query()->where('event_key', $event->event_key)->where('consumer', 'm05')->exists()) {
            return;
        }
        $actions->process($event->aggregate_id);
        ProcessedEvent::unguarded(fn () => ProcessedEvent::query()->firstOrCreate(['event_key' => $event->event_key, 'consumer' => 'm05'], ['processed_at' => now()]));
    }

    public function failed(?\Throwable $error): void
    {
        $event = OutboxEvent::query()->find($this->eventId);
        if ($event?->event_type === 'tasks.operation' && $event->aggregate_type === 'bulk_operation') {
            app(TaskOperation::class)->interrupted($event->aggregate_id);
        }
    }
}
