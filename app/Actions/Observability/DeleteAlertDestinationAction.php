<?php

namespace App\Actions\Observability;

use App\Models\AlertDestination;
use App\Services\Entitlements;

class DeleteAlertDestinationAction
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Delete an authorized alert destination after rechecking the paid-alert entitlement.
     */
    public function handle(AlertDestination $destination): void
    {
        $this->entitlements->enforce($destination->organization, 'alerts');
        $destination->delete();
    }
}
