<?php

namespace App\Jobs;

use App\Actions\Communications\ManageLetters;
use App\Models\OutboxEvent;
use App\Models\ProcessedEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateLetter implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 90;

    public function __construct(public int $eventId)
    {
        $this->onConnection('redis')->onQueue('exports');
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(ManageLetters $letters): void
    {
        $event = OutboxEvent::query()->where('event_type', 'letter.generate')->where('aggregate_type', 'communication')->findOrFail($this->eventId);
        if (ProcessedEvent::query()->where('event_key', $event->event_key)->where('consumer', 'letter-pdf')->exists()) {
            return;
        }
        $letters->generate($event->aggregate_id);
        ProcessedEvent::unguarded(fn () => ProcessedEvent::query()->firstOrCreate(['event_key' => $event->event_key, 'consumer' => 'letter-pdf'], ['processed_at' => now()]));
    }

    public function failed(?\Throwable $exception): void
    {
        $event = OutboxEvent::query()->where('event_type', 'letter.generate')->find($this->eventId);
        if ($event) {
            app(ManageLetters::class)->terminal($event->aggregate_id, eventId: $event->id);
        }
    }
}
