<?php

namespace App\Contracts\Providers;

/** Trusted, already-authorized and frozen communication data. Never log or serialize this DTO. */
final readonly class DeliveryRequest
{
    public function __construct(
        public string $communicationReference,
        public string $requestKey,
        public string $recipient,
        public string $body,
        public ?string $subject = null,
        public ?string $providerTemplateReference = null,
    ) {}
}
