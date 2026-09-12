<?php

namespace App\Actions\Observability;

use App\Jobs\DeliverAlertWebhookJob;
use App\Models\AlertDestination;
use App\Services\Entitlements;
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
            'title' => 'BuildPusher test alert',
            'message' => 'Your alert destination is connected.',
            'occurred_at' => now()->toIso8601String(),
        ]);
    }
}
