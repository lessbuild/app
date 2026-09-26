<?php

namespace App\Modules\Deployer\Services;

use App\Modules\Deployer\Models\OperationalIncident;
use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\Core\DeployerProjectAccess;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OperationalIncidentQuery
{
    /**
     * Build the current-workspace incident query used by the evidence export.
     *
     * @return HasMany<OperationalIncident, Organization> The organization-scoped export query.
     */
    public function forExport(Organization $organization, ?User $actor = null): HasMany
    {
        return $organization->operationalIncidents()
            ->when($actor !== null, fn ($query) => app(DeployerProjectAccess::class)->incidents($query, $actor))
            ->with('assignee')
            ->latest('detected_at');
    }
}
