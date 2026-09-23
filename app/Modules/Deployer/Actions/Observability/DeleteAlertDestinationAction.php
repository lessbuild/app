<?php

namespace App\Modules\Deployer\Actions\Observability;

use App\Modules\Deployer\Models\AlertDestination;
use App\Modules\Deployer\Services\Entitlements;

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
