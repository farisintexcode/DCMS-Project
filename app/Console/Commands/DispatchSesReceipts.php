<?php

namespace App\Console\Commands;

use App\Jobs\ProcessSesReceipt;
use App\Models\OutboxEvent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('communications:dispatch-receipts')]
#[Description('Publish verified SES receipt inbox work to Redis, retaining unresolved evidence')]
class DispatchSesReceipts extends Command
{
    public function handle(): int
    {
        OutboxEvent::query()->where('event_type', 'ses.receipt')->where('dispatched_at', '<', now()->subMinutes(5))->whereNotExists(fn ($q) => $q->selectRaw('1')->from('processed_events')->whereColumn('processed_events.event_key', 'outbox_events.event_key')->where('consumer', 'ses-receipt'))->update(['dispatched_at' => null]);
        $sent = 0;
        $failed = false;
        for ($i = 0; $i < 25; $i++) {
            $ok = DB::transaction(function () use (&$failed): bool {
                $event = OutboxEvent::query()->where('event_type', 'ses.receipt')->where('aggregate_type', 'integration_event')->whereNull('dispatched_at')->where('available_at', '<=', now())->orderBy('id')->lock('for update skip locked')->first();
                if (! $event) {
                    return false;
                }
                try {
                    ProcessSesReceipt::dispatch($event->id)->beforeCommit();
                    $event->forceFill(['dispatched_at' => now(), 'attempts' => $event->attempts + 1, 'last_error' => null])->save();

                    return true;
                } catch (\Throwable) {
                    $failed = true;
                    $event->forceFill(['attempts' => $event->attempts + 1, 'available_at' => now()->addMinute(), 'last_error' => 'Receipt queue unavailable; verified work retained.'])->save();

                    return false;
                }
            });
            if (! $ok) {
                break;
            } $sent++;
        }
        $this->info("Published {$sent} verified receipt work item(s).");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
