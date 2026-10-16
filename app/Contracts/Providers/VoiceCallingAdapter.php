<?php

namespace App\Contracts\Providers;

/** A separate provider-qualified boundary; messaging capability never implies voice support. */
interface VoiceCallingAdapter
{
    public function supportsOutboundCalling(): bool;

    public function start(string $requestKey, string $recipient, string $agentRoute, string $verifiedPermissionReference): DeliveryResult;

    public function end(string $providerCallReference): void;

    public function verifyWebhook(string $rawBody, array $headers): bool;
}
