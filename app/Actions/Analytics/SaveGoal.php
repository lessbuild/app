<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Data\Analytics\GoalDetails;
use App\Models\AnalyticsGoal;
use App\Models\AnalyticsSite;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class SaveGoal
{
    /**
     * Creates or changes a goal.
     *
     * @param  RebuildSiteReports  $rebuild  Recounts the site's history against the goal.
     */
    public function __construct(private readonly RebuildSiteReports $rebuild) {}

    /** Create or change a goal, then recount conversions (a changed definition starts a new goal version). */
    public function handle(User $actor, AnalyticsSite $site, GoalDetails $details, ?AnalyticsGoal $goal = null): AnalyticsGoal
    {
        Gate::forUser($actor)->authorize('update', $site);

        $goal ??= new AnalyticsGoal(['site_id' => $site->id]);
        $goal->fill([
            'name' => trim($details->name),
            'kind' => $details->kind,
            'match_type' => $details->matchType,
            'match_value' => trim($details->matchValue),
            'active' => $details->active,
        ])->save();
        $this->rebuild->handle($site);

        return $goal;
    }
}
