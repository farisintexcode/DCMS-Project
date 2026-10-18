<?php

namespace App\Console\Commands;

use App\Jobs\ProcessImport;
use App\Models\OutboxEvent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('imports:dispatch')]
#[Description('Publish pending M04 outbox events to the imports queue')]
class DispatchImports extends Command
{
    public function handle(): int
    {
        $sent = 0;
        $unavailable = false;
        for ($index = 0; $index < 25; $index++) {
            $published = DB::transaction(function () use (&$unavailable): bool {
                $event = OutboxEvent::query()->where('aggregate_type', 'import')->whereIn('event_type', ['import.scan', 'import.commit'])->whereNull('dispatched_at')->where('available_at', '<=', now())->orderBy('id')->lock('for update skip locked')->first();
                if (! $event) {
                    return false;
                }
                try {
                    ProcessImport::dispatch($event->id)->beforeCommit();
                    $event->dispatched_at = now();
                    $event->attempts++;
                    $event->last_error = null;
                    $event->save();

                    return true;
                } catch (\Throwable) {
                    $unavailable = true;
                    $event->attempts++;
                    $event->available_at = now()->addMinute();
                    $event->last_error = 'Queue publication failed; retry scheduled.';
                    $event->save();

                    return false;
                }
            });
            if (! $published) {
                break;
            } $sent++;
        }
        $this->info("Published {$sent} import event(s). Pending events remain durable for retry.");

        if ($unavailable) {
            $this->error('Queue unavailable. Events remain pending; publication will retry.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
