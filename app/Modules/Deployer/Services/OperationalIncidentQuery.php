<?php

namespace App\Modules\Deployer\Services;

use App\Modules\Deployer\Models\OperationalIncident;
use App\Modules\Deployer\Models\Organization;
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
