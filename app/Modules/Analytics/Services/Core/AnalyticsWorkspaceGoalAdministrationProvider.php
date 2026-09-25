<?php

namespace App\Modules\Analytics\Services\Core;

use App\Core\Contracts\Analytics\WorkspaceAnalyticsGoalAdministrationProvider;
use App\Core\Data\Analytics\AnalyticsGoalInput;
use App\Core\Data\Analytics\AnalyticsGoalSnapshot;
use App\Core\Data\Analytics\AnalyticsGoalSummary;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Modules\Analytics\Actions\Collection\RebuildSiteVisits;
use App\Modules\Analytics\Actions\Goals\RebuildGoalConversions;
use App\Modules\Analytics\Actions\Reporting\RebuildReportAggregates;
use App\Modules\Analytics\Models\Goal;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Models\Workspace;
use App\Modules\Analytics\Services\Deletion\AnalyticsDeletionFence;
use Illuminate\Database\LostConnectionException;
use Illuminate\Support\Facades\DB;
use PDOException;

final class AnalyticsWorkspaceGoalAdministrationProvider implements WorkspaceAnalyticsGoalAdministrationProvider
{
    public function __construct(
        private readonly AnalyticsCoreAdministrationAccess $nativeAccess,
        private readonly RebuildSiteVisits $rebuildVisits,
        private readonly RebuildGoalConversions $rebuildConversions,
        private readonly RebuildReportAggregates $rebuildReports,
    ) {}

    public function snapshot(PlatformUser $user, CoreWorkspace $workspace, string $siteId): ?AnalyticsGoalSnapshot
    {
        try {
            $nativeWorkspace = $this->nativeAccess->workspace($user, $workspace);
            $site = $this->nativeAccess->site($user, $nativeWorkspace, $siteId);
            $role = $this->nativeAccess->role($user, $nativeWorkspace);
            $goals = $site->goals()->orderBy('id')->limit(100)->get()
                ->map(fn (Goal $goal): AnalyticsGoalSummary => new AnalyticsGoalSummary(
                    id: (string) $goal->getKey(), name: (string) $goal->name, kind: (string) $goal->kind,
                    matchType: (string) $goal->match_type, matchValue: (string) $goal->match_value, active: (bool) $goal->active,
                ))->all();

            return new AnalyticsGoalSnapshot((string) $site->getKey(), (string) $site->name, $goals, $role?->canManageSites() === true);
        } catch (LostConnectionException|PDOException) {
            return new AnalyticsGoalSnapshot($siteId, '', [], false, available: false);
        }
    }

    public function create(PlatformUser $user, CoreWorkspace $workspace, string $siteId, AnalyticsGoalInput $input): void
    {
        $this->mutate($user, $workspace, $siteId, function (Site $site) use ($input): void {
            $site->goals()->create($this->attributes($input));
            $this->rebuild($site);
        });
    }

    public function update(PlatformUser $user, CoreWorkspace $workspace, string $siteId, string $goalId, AnalyticsGoalInput $input): void
    {
        $this->mutate($user, $workspace, $siteId, function (Site $site) use ($goalId, $input): void {
            $goal = Goal::query()->where('site_id', $site->getKey())->whereKey($goalId)->lockForUpdate()->firstOrFail();
            $goal->update($this->attributes($input));
            $this->rebuild($site);
        });
    }

    public function delete(PlatformUser $user, CoreWorkspace $workspace, string $siteId, string $goalId): void
    {
        $this->mutate($user, $workspace, $siteId, function (Site $site) use ($goalId): void {
            $goal = Goal::query()->where('site_id', $site->getKey())->whereKey($goalId)->lockForUpdate()->firstOrFail();
            $goal->delete();
            $this->rebuild($site);
        });
    }

    /** @return array{name:string,kind:string,match_type:string,match_value:string,active:bool} */
    private function attributes(AnalyticsGoalInput $input): array
    {
        $name = trim($input->name);
        abort_if($name === '' || mb_strlen($name) > 120, 422);
        abort_unless(in_array($input->kind, ['path', 'event'], true), 422);
        abort_unless(in_array($input->matchType, ['exact', 'prefix'], true), 422);
        abort_if(trim($input->matchValue) === '' || mb_strlen($input->matchValue) > 255, 422);

        return [
            'name' => $name, 'kind' => $input->kind, 'match_type' => $input->matchType,
            'match_value' => trim($input->matchValue), 'active' => $input->active,
        ];
    }

    private function mutate(PlatformUser $user, CoreWorkspace $coreWorkspace, string $siteId, callable $operation): void
    {
        $nativeWorkspace = $this->nativeAccess->workspace($user, $coreWorkspace);
        abort_unless($this->nativeAccess->role($user, $nativeWorkspace)?->canManageSites() === true, 403);
        DB::connection('analytics')->transaction(function () use ($user, $coreWorkspace, $nativeWorkspace, $siteId, $operation): void {
            $workspace = Workspace::query()->whereKey($nativeWorkspace->getKey())->lockForUpdate()->firstOrFail();
            $current = $this->nativeAccess->workspace($user, $coreWorkspace);
            abort_unless((string) $current->getKey() === (string) $workspace->getKey(), 404);
            abort_unless($this->nativeAccess->role($user, $workspace)?->canManageSites() === true, 403);
            app(AnalyticsDeletionFence::class)->assertWorkspaceOpen($workspace->getKey());
            $site = Site::query()->where('workspace_id', $workspace->getKey())->whereKey($siteId)->lockForUpdate()->firstOrFail();
            app(AnalyticsDeletionFence::class)->assertSiteOpen($site->getKey());
            $this->nativeAccess->site($user, $workspace, (string) $site->getKey(), manage: true);
            $operation($site);
        }, attempts: 3);
    }

    private function rebuild(Site $site): void
    {
        $this->rebuildVisits->handle($site);
        $this->rebuildConversions->handle($site);
        $this->rebuildReports->handle($site);
    }
}
