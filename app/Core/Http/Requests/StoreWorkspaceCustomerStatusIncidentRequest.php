<?php

namespace App\Core\Http\Requests;

final class StoreWorkspaceCustomerStatusIncidentRequest extends WorkspaceStatusIncidentRequest
{
    protected function requiresStatusPage(): bool
    {
        return true;
    }
}
