<?php

namespace App\Modules\Deployer\Actions\Observability;

use App\Modules\Deployer\Models\AlertDestination;
use App\Modules\Deployer\Services\Entitlements;
use App\Modules\Deployer\Services\QueueAlertWebhookDelivery;
use Illuminate\Support\Str;

class QueueAlertDestinationTestAction
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly QueueAlertWebhookDelivery $deliveries,
    ) {}

    /**
     * Queue the existing bounded test payload for an authorized alert destination.
     */
    public function handle(AlertDestination $destination): void
    {
        $this->entitlements->enforce($destination->organization, 'alerts');
        $this->deliveries->enqueue($destination, [
            'id' => (string) Str::uuid(),
            'event' => 'failure',
            'category' => 'test',
            'resource_id' => 0,
            'title' => config('app.name', 'Deployer').' test alert',
            'message' => 'Your alert destination is connected.',
            'occurred_at' => now()->toIso8601String(),
        ]);
    }
}
