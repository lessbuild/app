<?php

namespace App\Modules\Deployer\Services;

use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\ObservabilityInvestigationView;
use App\Modules\Deployer\Models\User;
use Illuminate\Database\Eloquent\Collection;

class ObservabilityInvestigationViewQuery
{
    public const MAX_VIEWS = 20;

    /**
     * Load active named views for the current environment and workspace.
     *
     * @return Collection<int, ObservabilityInvestigationView> Bounded active views with safe creator metadata.
     */
    public function for(User $user, Environment $environment): Collection
    {
        return $environment->investigationViews()
            ->where('organization_id', $user->current_organization_id)
            ->where('expires_at', '>', now())
            ->with('creator:id,name')
            ->latest('updated_at')
            ->latest('id')
            ->limit(self::MAX_VIEWS)
            ->get();
    }
}
