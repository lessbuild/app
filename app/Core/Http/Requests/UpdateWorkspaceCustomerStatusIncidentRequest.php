<?php

namespace App\Core\Http\Requests;

final class UpdateWorkspaceCustomerStatusIncidentRequest extends WorkspaceStatusIncidentRequest
{
    protected function requiresStatusPage(): bool
    {
        return false;
    }
}
