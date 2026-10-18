<?php

namespace App\Jobs;

use App\Actions\Imports\ImportWorkflow;
use App\Models\OutboxEvent;
use App\Models\ProcessedEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** M04: at-least-once delivery; business rows are independently atomic and replay safe. */
class ProcessImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $eventId)
    {
        $this->onQueue(config('imports.queue'));
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function failed(?\Throwable $error): void
    {
        $event = OutboxEvent::query()->find($this->eventId);
        if ($event && in_array($event->event_type, ['import.scan', 'import.commit'], true)) {
            app(ImportWorkflow::class)->interrupted($event->aggregate_id);
        }
    }

    public function handle(ImportWorkflow $workflow): void
    {
        $event = OutboxEvent::query()->findOrFail($this->eventId);
        if (ProcessedEvent::query()->where('event_key', $event->event_key)->where('consumer', 'm04')->exists()) {
            return;
        }
        if ($event->event_type === 'import.scan') {
            $workflow->scan($event->aggregate_id);
        } elseif ($event->event_type === 'import.commit') {
            $workflow->process($event->aggregate_id);
        } else {
            return;
        }
        ProcessedEvent::unguarded(fn () => ProcessedEvent::query()->firstOrCreate(['event_key' => $event->event_key, 'consumer' => 'm04'], ['processed_at' => now()]));
    }
}
