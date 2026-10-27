<?php

namespace App\Console\Commands;

use App\Jobs\GenerateReport;
use App\Models\OutboxEvent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('reports:dispatch')]
#[Description('Publish confirmed report exports to Redis with durable recovery')]
class DispatchReports extends Command
{
    public function handle(): int
    {
        OutboxEvent::query()->where('event_type', 'report.generate')->where('dispatched_at', '<', now()->subMinutes(5))->whereNotExists(fn ($q) => $q->selectRaw('1')->from('processed_events')->whereColumn('processed_events.event_key', 'outbox_events.event_key')->where('consumer', 'report-export'))->update(['dispatched_at' => null]);
        $sent = 0;
        $failed = false;
        for ($i = 0; $i < 25; $i++) {
            $ok = DB::transaction(function () use (&$failed): bool {
                $event = OutboxEvent::query()->where('event_type', 'report.generate')->where('aggregate_type', 'export_job')->whereNull('dispatched_at')->where('available_at', '<=', now())->orderBy('id')->lock('for update skip locked')->first();
                if (! $event) {
                    return false;
                }
                try {
                    GenerateReport::dispatch($event->id)->beforeCommit();
                    $event->forceFill(['dispatched_at' => now(), 'attempts' => $event->attempts + 1, 'last_error' => null])->save();

                    return true;
                } catch (\Throwable) {
                    $failed = true;
                    $event->forceFill(['attempts' => $event->attempts + 1, 'available_at' => now()->addMinute(), 'last_error' => 'Report queue unavailable; confirmed work retained.'])->save();

                    return false;
                }
            });
            if (! $ok) {
                break;
            } $sent++;
        }
        $this->info("Published {$sent} report export work item(s).");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
