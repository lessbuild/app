<?php

declare(strict_types=1);

namespace App\Queries\Analytics;

use App\Models\AnalyticsSite;
use App\Models\Deployment;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * The releases that went live while a site's report covers, so a change in traffic can be read beside what shipped.
 * They're Monitoring's deployment markers (recorded by every live deploy), for the site's environment, or for all of
 * the project's environments when the site isn't tied to one.
 */
final class SiteReleasesQuery
{
    /**
     * Get up to 20 deployments in the period, newest first, with their releases.
     *
     * @param  AnalyticsSite  $site
     * @param  CarbonInterface  $start
     * @param  CarbonInterface  $end
     * @return Collection<int, Deployment>
     */
    public function handle(AnalyticsSite $site, CarbonInterface $start, CarbonInterface $end): Collection
    {
        $environments = $site->environment_id !== null ? [$site->environment_id] : $site->project->environments()->pluck('id')->all();

        return Deployment::query()->whereIn('environment_id', $environments)->whereBetween('deployed_at', [$start, $end])
            ->with(['release', 'environment'])->latest('deployed_at')->limit(20)->get();
    }
}
