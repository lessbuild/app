<?php

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Actions\Collection\RebuildSiteVisits;
use App\Modules\Analytics\Actions\Goals\RebuildGoalConversions;
use App\Modules\Analytics\Actions\Reporting\RebuildReportAggregates;
use App\Modules\Analytics\Models\Goal;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Services\Deletion\AnalyticsDeletionFence;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class GoalController extends Controller
{
    public function index(Request $request, Site $site): View
    {
        $this->authorize('view', $site);

        return view('analytics::goals.index', ['site' => $site, 'goals' => $site->goals()->latest()->get(), 'canManage' => $request->user()->can('manage', $site)]);
    }

    public function create(Site $site): View
    {
        $this->authorize('manage', $site);

        return view('analytics::goals.create', compact('site'));
    }

    public function store(Request $request, Site $site, RebuildSiteVisits $rebuildSiteVisits, RebuildGoalConversions $rebuildGoalConversions, RebuildReportAggregates $rebuildReportAggregates, AnalyticsDeletionFence $fence): RedirectResponse
    {
        $attributes = $request->validate($this->rules());
        $this->mutateSite($site, $fence, function (Site $lockedSite) use ($attributes, $rebuildSiteVisits, $rebuildGoalConversions, $rebuildReportAggregates): void {
            $lockedSite->goals()->create($attributes);
            $rebuildSiteVisits->handle($lockedSite);
            $rebuildGoalConversions->handle($lockedSite);
            $rebuildReportAggregates->handle($lockedSite);
        });

        return to_route('analytics.goals.index', $site)->with('status', 'Goal created.');
    }

    public function edit(Site $site, Goal $goal): View
    {
        $this->authorize('manage', $site);
        abort_unless($goal->site_id === $site->id, 404);

        return view('analytics::goals.edit', compact('site', 'goal'));
    }

    public function update(Request $request, Site $site, Goal $goal, RebuildSiteVisits $rebuildSiteVisits, RebuildGoalConversions $rebuildGoalConversions, RebuildReportAggregates $rebuildReportAggregates, AnalyticsDeletionFence $fence): RedirectResponse
    {
        $attributes = $request->validate($this->rules());
        $this->mutateSite($site, $fence, function (Site $lockedSite) use ($goal, $attributes, $rebuildSiteVisits, $rebuildGoalConversions, $rebuildReportAggregates): void {
            $lockedGoal = Goal::query()->where('site_id', $lockedSite->getKey())->whereKey($goal->getKey())->lockForUpdate()->firstOrFail();
            $lockedGoal->update($attributes);
            $rebuildSiteVisits->handle($lockedSite);
            $rebuildGoalConversions->handle($lockedSite);
            $rebuildReportAggregates->handle($lockedSite);
        });

        return to_route('analytics.goals.index', $site)->with('status', 'Goal updated.');
    }

    public function destroy(Site $site, Goal $goal, RebuildSiteVisits $rebuildSiteVisits, RebuildGoalConversions $rebuildGoalConversions, RebuildReportAggregates $rebuildReportAggregates, AnalyticsDeletionFence $fence): RedirectResponse
    {
        $this->mutateSite($site, $fence, function (Site $lockedSite) use ($goal, $rebuildSiteVisits, $rebuildGoalConversions, $rebuildReportAggregates): void {
            Goal::query()->where('site_id', $lockedSite->getKey())->whereKey($goal->getKey())->lockForUpdate()->firstOrFail()->delete();
            $rebuildSiteVisits->handle($lockedSite);
            $rebuildGoalConversions->handle($lockedSite);
            $rebuildReportAggregates->handle($lockedSite);
        });

        return to_route('analytics.goals.index', $site)->with('status', 'Goal removed.');
    }

    /** @return array<string, array<int, string>> */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', 'string', 'in:path,event'],
            'match_type' => ['required', 'string', 'in:exact,prefix'],
            'match_value' => ['required', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    private function mutateSite(Site $site, AnalyticsDeletionFence $fence, callable $operation): void
    {
        DB::connection('analytics')->transaction(function () use ($site, $fence, $operation): void {
            $workspace = $site->workspace()->lockForUpdate()->firstOrFail();
            $lockedSite = Site::query()->where('workspace_id', $workspace->getKey())->whereKey($site->getKey())->lockForUpdate()->firstOrFail();
            $fence->assertWorkspaceOpen($workspace->getKey());
            $fence->assertSiteOpen($lockedSite->getKey());
            $this->authorize('manage', $lockedSite);
            $operation($lockedSite);
        }, attempts: 3);
    }
}
