<?php

namespace App\Http\Requests;

use App\Models\StatusIncident;
use App\Services\Entitlements;

class StoreStatusIncidentRequest extends StatusIncidentRequest
{
    /**
     * Preserve management authorization and status-page entitlement checks before validation.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user || ! $user->can('create', StatusIncident::class)) {
            return false;
        }

        app(Entitlements::class)->enforce($user->currentOrganization, 'status_pages');

        return true;
    }

    protected function incidentOrganizationId(): ?int
    {
        return $this->user()?->current_organization_id;
    }

    protected function requiresStatusPage(): bool
    {
        return true;
    }
}
