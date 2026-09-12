<?php

namespace App\Http\Requests;

use App\Models\StatusIncident;
use App\Services\Entitlements;

class UpdateStatusIncidentRequest extends StatusIncidentRequest
{
    /**
     * Preserve incident workspace authorization and status-page entitlement checks before validation.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $incident = $this->route('incident');
        if (! $user || ! $incident instanceof StatusIncident || ! $user->can('update', $incident)) {
            return false;
        }

        app(Entitlements::class)->enforce($incident->statusPage->organization, 'status_pages');

        return true;
    }

    protected function incidentOrganizationId(): ?int
    {
        $incident = $this->route('incident');

        return $incident instanceof StatusIncident ? $incident->statusPage->organization_id : null;
    }

    protected function requiresStatusPage(): bool
    {
        return false;
    }
}
