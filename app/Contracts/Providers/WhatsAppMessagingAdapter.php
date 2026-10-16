<?php

namespace App\Contracts\Providers;

interface WhatsAppMessagingAdapter
{
    public function send(DeliveryRequest $request): DeliveryResult;

    public function reconcile(string $requestKey, ?string $providerReference): DeliveryResult;

    public function verifyWebhook(string $rawBody, array $headers): bool;
}
