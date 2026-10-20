<?php

namespace App\Console\Commands;

use App\Actions\Communications\DeliverEmail;
use App\Jobs\DeliverServiceEmail;
use App\Models\OutboxEvent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('communications:dispatch')]
#[Description('Recover ambiguous email attempts and publish durable email work to Redis')]
class DispatchEmails extends Command
{
    public function handle(DeliverEmail $delivery): int
    {
        $delivery->recover();
        OutboxEvent::query()->where('event_type', 'email.deliver')->where('aggregate_type', 'communication')->where('dispatched_at', '<', now()->subMinutes(5))->whereNotExists(fn ($q) => $q->selectRaw('1')->from('processed_events')->whereColumn('processed_events.event_key', 'outbox_events.event_key')->where('consumer', 'm14-email'))->update(['dispatched_at' => null]);
        $sent = 0;
        $unavailable = false;
        for ($index = 0; $index < 25; $index++) {
            $published = DB::transaction(function () use (&$unavailable): bool {
                $event = OutboxEvent::query()->where('event_type', 'email.deliver')->where('aggregate_type', 'communication')->whereNull('dispatched_at')->where('available_at', '<=', now())->orderBy('id')->lock('for update skip locked')->first();
                if (! $event) {
                    return false;
                }
                try {
                    DeliverServiceEmail::dispatch($event->id)->beforeCommit();
                    $event->forceFill(['dispatched_at' => now(), 'attempts' => $event->attempts + 1, 'last_error' => null])->save();

                    return true;
                } catch (\Throwable) {
                    $unavailable = true;
                    $event->forceFill(['attempts' => $event->attempts + 1, 'available_at' => now()->addMinute(), 'last_error' => 'Email queue unavailable; publication retained for retry.'])->save();

                    return false;
                }
            });
            if (! $published) {
                break;
            } $sent++;
        }
        $this->info("Published {$sent} durable email work item(s). Unpublished work remains retained.");

        return $unavailable ? self::FAILURE : self::SUCCESS;
    }
}
