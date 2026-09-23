<?php

namespace App\Modules\Deployer\Actions\Observability;

use App\Modules\Deployer\Jobs\DeliverAlertWebhookJob;
use App\Modules\Deployer\Models\AlertDestination;
use App\Modules\Deployer\Services\Entitlements;
use Illuminate\Support\Str;

class QueueAlertDestinationTestAction
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Queue the existing bounded test payload for an authorized alert destination.
     */
    public function handle(AlertDestination $destination): void
    {
        $this->entitlements->enforce($destination->organization, 'alerts');
        DeliverAlertWebhookJob::dispatch($destination->id, [
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
