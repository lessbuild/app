<?php

namespace App\Actions\Observability;

use App\Models\StatusPage;
use App\Services\Entitlements;

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
