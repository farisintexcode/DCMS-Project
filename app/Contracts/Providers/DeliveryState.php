<?php

namespace App\Contracts\Providers;

enum DeliveryState: string
{
    case Accepted = 'accepted';
    case RetryableFailure = 'retryable_failure';
    case PermanentFailure = 'permanent_failure';
    case Unknown = 'unknown';
}
