<?php

namespace App\Jobs;

use App\Actions\Reports\ManageReports;
use App\Models\OutboxEvent;
use App\Models\ProcessedEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $eventId)
    {
        $this->onConnection('redis')->onQueue('exports');
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(ManageReports $reports): void
    {
        $event = OutboxEvent::query()->where('event_type', 'report.generate')->where('aggregate_type', 'export_job')->findOrFail($this->eventId);
        if (ProcessedEvent::query()->where('event_key', $event->event_key)->where('consumer', 'report-export')->exists()) {
            return;
        }
        $reports->generate($event->aggregate_id);
        ProcessedEvent::unguarded(fn () => ProcessedEvent::query()->firstOrCreate(['event_key' => $event->event_key, 'consumer' => 'report-export'], ['processed_at' => now()]));
    }

    public function failed(?\Throwable $exception): void
    {
        if ($event = OutboxEvent::query()->where('event_type', 'report.generate')->find($this->eventId)) {
            app(ManageReports::class)->failed($event->aggregate_id);
        }
    }
}
