<?php

namespace App\Modules\Deployer\Actions\Observability;

use App\Modules\Deployer\Models\StatusPage;
use App\Modules\Deployer\Services\Entitlements;

class DeleteStatusPageAction
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Delete an authorized status page after rechecking the status-page entitlement.
     */
    public function handle(StatusPage $page): void
    {
        $this->entitlements->enforce($page->organization, 'status_pages');
        $page->delete();
    }
}
