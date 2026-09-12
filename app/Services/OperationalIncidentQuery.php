<?php

namespace App\Services;

use App\Models\OperationalIncident;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OperationalIncidentQuery
{
    /**
     * Build the current-workspace incident query used by the evidence export.
     *
     * @return HasMany<OperationalIncident, Organization> The organization-scoped export query.
     */
    public function forExport(Organization $organization): HasMany
    {
        return $organization->operationalIncidents()
            ->with('assignee')
            ->latest('detected_at');
    }
}
