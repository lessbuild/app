<?php

namespace App\Listeners;

use App\Jobs\SyncOrganizationSeatQuantityJob;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Cashier\Events\WebhookHandled;

final class SyncSeatsAfterBillingWebhook
{
    /**
     * Resolve the workspace affected by a completed Cashier webhook and queue seat reconciliation.
     *
     * @param  WebhookHandled  $event  The verified webhook payload already processed by Cashier.
     * @return void Dispatch one existing reconciliation job when a workspace can be identified.
     */
    public function handle(WebhookHandled $event): void
    {
        $object = data_get($event->payload, 'data.object', []);
        $organizationId = data_get($object, 'metadata.organization_id');
        if (! $organizationId && ($customer = data_get($object, 'customer'))) {
            $organizationId = Organization::query()
                ->whereHas('owner', fn (Builder $query): Builder => $query->where('stripe_id', $customer))
                ->value('id');
        }
        if ($organizationId) {
            SyncOrganizationSeatQuantityJob::dispatch((int) $organizationId);
        }
    }
}
