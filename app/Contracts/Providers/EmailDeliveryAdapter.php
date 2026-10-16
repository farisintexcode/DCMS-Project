<?php

namespace App\Contracts\Providers;

/** Plan.md Integration boundaries / Module.md M14. No provider is enabled by default. */
interface EmailDeliveryAdapter
{
    public function send(DeliveryRequest $request): DeliveryResult;

    public function reconcile(string $requestKey, ?string $providerReference): DeliveryResult;
}
