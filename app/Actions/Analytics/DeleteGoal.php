<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Models\AnalyticsGoal;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class DeleteGoal
{
    /**
     * Deletes a goal.
     *
     * @param  RebuildSiteReports  $rebuild  Recounts the site's history without it.
     */
    public function __construct(private readonly RebuildSiteReports $rebuild) {}

    /**
     * Deletes the goal and rebuilds the site's visits, conversions and totals so it disappears from past reports too.
     */
    public function handle(User $actor, AnalyticsGoal $goal): void
    {
        $site = $goal->site;
        Gate::forUser($actor)->authorize('update', $site);

        $goal->delete();
        $this->rebuild->handle($site);
    }
}
