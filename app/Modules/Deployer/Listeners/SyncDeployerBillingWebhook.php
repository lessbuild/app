<?php

namespace App\Modules\Deployer\Listeners;

use App\Modules\Deployer\Services\DeployerPlanAuthority;
use App\Modules\Deployer\Services\SyncDeployerBillingEventIntoCore;
use Laravel\Cashier\Events\WebhookReceived;

final class SyncDeployerBillingWebhook
{
    public function __construct(
        private readonly DeployerPlanAuthority $planAuthority,
        private readonly SyncDeployerBillingEventIntoCore $billingEvents,
    ) {}

    public function handle(WebhookReceived $event): void
    {
        if (! $this->planAuthority->usesCore()) {
            return;
        }

        $this->billingEvents->handle($event->payload);
    }
}
