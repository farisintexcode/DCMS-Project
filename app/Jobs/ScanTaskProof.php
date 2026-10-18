<?php

namespace App\Jobs;

use App\Actions\Tasks\TaskProof;
use App\Models\Document;
use App\Models\OutboxEvent;
use App\Models\ProcessedEvent;
use App\Services\Imports\MalwareScanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ScanTaskProof implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $eventId)
    {
        $this->onQueue('media');
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(TaskProof $proof, MalwareScanner $scanner): void
    {
        $event = OutboxEvent::query()->findOrFail($this->eventId);
        if ($event->event_type !== 'tasks.proof_scan' || $event->aggregate_type !== 'document' || ProcessedEvent::query()->where('event_key', $event->event_key)->where('consumer', 'm06')->exists()) {
            return;
        }
        $proof->scan($event->aggregate_id, $scanner);
        ProcessedEvent::unguarded(fn () => ProcessedEvent::query()->firstOrCreate(['event_key' => $event->event_key, 'consumer' => 'm06'], ['processed_at' => now()]));
    }

    public function failed(?\Throwable $error): void
    {
        $event = OutboxEvent::query()->find($this->eventId);
        if ($event?->event_type === 'tasks.proof_scan' && $event->aggregate_type === 'document') {
            Document::query()->whereKey($event->aggregate_id)->where('scan_status', 'pending')->update(['scan_status' => 'error']);
        }
    }
}
