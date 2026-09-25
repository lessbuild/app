<?php

namespace App\Core\Services;

use App\Core\Contracts\WorkspaceWebhookDeliveryProvider;

final class WorkspaceWebhookDeliveryProviderRegistry
{
    /** @var array<string, WorkspaceWebhookDeliveryProvider> */
    private array $providers = [];

    public function register(string $product, WorkspaceWebhookDeliveryProvider $provider): void
    {
        $this->providers[$product] = $provider;
    }

    public function get(string $product): ?WorkspaceWebhookDeliveryProvider
    {
        return $this->providers[$product] ?? null;
    }
}
