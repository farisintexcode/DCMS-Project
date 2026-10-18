<?php

namespace App\Console\Commands;

use App\Jobs\ProcessTaskOperation;
use App\Models\OutboxEvent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('tasks:dispatch')]
#[Description('Publish pending M05 outbox events to the default queue')]
class DispatchTaskOperations extends Command
{
    public function handle(): int
    {
        $sent = 0;
        $unavailable = false;
        for ($index = 0; $index < 25; $index++) {
            $published = DB::transaction(function () use (&$unavailable): bool {
                $event = OutboxEvent::query()->where('aggregate_type', 'bulk_operation')->where('event_type', 'tasks.operation')->whereNull('dispatched_at')->where('available_at', '<=', now())->orderBy('id')->lock('for update skip locked')->first();
                if (! $event) {
                    return false;
                }
                try {
                    ProcessTaskOperation::dispatch($event->id)->beforeCommit();
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
        $this->info("Published {$sent} task operation event(s). Pending events remain durable for retry.");

        if ($unavailable) {
            $this->error('Queue unavailable. Events remain pending; publication will retry.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
