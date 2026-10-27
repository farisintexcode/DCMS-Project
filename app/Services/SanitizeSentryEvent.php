<?php

namespace App\Services;

use Sentry\Event;

/** Plan.md §10: only a minimal incident signal leaves the application by default. */
class SanitizeSentryEvent
{
    public static function handle(Event $event): Event
    {
        $safe = Event::createEvent($event->getId());
        $safe->setLevel($event->getLevel());
        $safe->setMessage('Nexora application error. Inspect restricted application logs for details.');

        return $safe;
    }
}
