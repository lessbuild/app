<?php

namespace App\Services;

use App\Data\ObservabilityContextFilters;
use App\Models\Environment;
use App\Models\ObservabilityInvestigationView;
use App\Models\Repository;

class ObservabilityInvestigationViewResolver
{
    /**
     * Resolve a persisted view only while its environment and service identity remain valid.
     *
     * @return ObservabilityContextFilters|null The canonical filters, or null for stale/corrupt view data.
     */
    public function filters(ObservabilityInvestigationView $view): ?ObservabilityContextFilters
    {
        $environment = $view->environment;
        $filters = $view->contextFilters();

        if (! $environment instanceof Environment
            || (int) $view->organization_id !== (int) $environment->project->organization_id
            || $filters === null) {
            return null;
        }

        if ($filters->serviceId !== null && ! $this->serviceBelongsToEnvironment($environment, $filters->serviceId, (int) $view->organization_id)) {
            return null;
        }

        return $filters;
    }

    /** Check the current organization and website before persisting a service filter. */
    public function serviceBelongsToEnvironment(Environment $environment, int $serviceId, int $organizationId): bool
    {
        return Repository::query()
            ->whereKey($serviceId)
            ->where('organization_id', $organizationId)
            ->where('website_id', $environment->website_id)
            ->exists();
    }
}
