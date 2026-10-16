<?php

namespace App\Contracts\Providers;

/** Acceptance is not delivery; ambiguous outcomes must be reconciled before retry. */
final readonly class DeliveryResult
{
    public function __construct(
        public DeliveryState $state,
        public ?string $providerReference = null,
        public ?string $errorCode = null,
    ) {}
}
