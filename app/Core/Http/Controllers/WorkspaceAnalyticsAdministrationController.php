<?php

namespace App\Core\Http\Controllers;

use App\Core\Data\Analytics\AnalyticsGoalInput;
use App\Core\Data\Analytics\AnalyticsSiteInput;
use App\Core\Data\Analytics\AnalyticsSiteSettings;
use App\Core\Http\Requests\Analytics\CreateAnalyticsSiteRequest;
use App\Core\Http\Requests\Analytics\RequestAnalyticsReportRequest;
use App\Core\Http\Requests\Analytics\SaveAnalyticsGoalRequest;
use App\Core\Http\Requests\Analytics\UpdateAnalyticsSiteRequest;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\Workspace;
use App\Core\Services\WorkspaceAnalyticsAdministrationProviderRegistry;
use App\Core\Services\WorkspaceProjectAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class WorkspaceAnalyticsAdministrationController
{
    public function index(Request $request, Workspace $workspace, WorkspaceProjectAccess $access, WorkspaceAnalyticsAdministrationProviderRegistry $providers): View
    {
        $user = $this->authorizeWorkspace($request, $workspace, $access);
        $provider = $providers->sites();
        abort_if($provider === null, 503);
        $snapshot = $provider->snapshot($user, $workspace);

        return view('core::workspaces.analytics.index', $this->pageData($user, $workspace) + compact('snapshot'));
    }

    public function create(CreateAnalyticsSiteRequest $request, Workspace $workspace, WorkspaceProjectAccess $access, WorkspaceAnalyticsAdministrationProviderRegistry $providers): RedirectResponse
    {
        $user = $this->authorizeWorkspace($request, $workspace, $access);
        $provider = $providers->sites();
        abort_if($provider === null, 503);
        $setup = $provider->create($user, $workspace, new AnalyticsSiteInput(
            (string) $request->validated('name'), (string) $request->validated('domain'), (string) $request->validated('timezone'),
        ));

        return to_route('core.workspace.analytics.sites.setup', [$workspace, $setup->id]);
    }

    public function setup(Request $request, Workspace $workspace, string $site, WorkspaceProjectAccess $access, WorkspaceAnalyticsAdministrationProviderRegistry $providers): View
    {
        $user = $this->authorizeWorkspace($request, $workspace, $access);
        $provider = $providers->sites();
        abort_if($provider === null, 503);
        $setup = $provider->setup($user, $workspace, $site);
        abort_if($setup === null, 404);

        return view('core::workspaces.analytics.setup', $this->pageData($user, $workspace) + compact('setup', 'workspace'));
    }

    public function verify(Request $request, Workspace $workspace, string $site, WorkspaceProjectAccess $access, WorkspaceAnalyticsAdministrationProviderRegistry $providers): RedirectResponse
    {
        $user = $this->authorizeWorkspace($request, $workspace, $access);
        $token = $request->input('token');
        abort_unless(is_string($token) && trim($token) !== '' && strlen($token) <= 128, 422);
        $provider = $providers->sites();
        abort_if($provider === null, 503);
        if (! $provider->verify($user, $workspace, $site, $token)) {
            return back()->withErrors(['token' => __('The domain has not presented the required Analytics verification record.')]);
        }

        return to_route('core.workspace.analytics.sites.setup', [$workspace, $site])->with('status', __('Domain verified.'));
    }

    public function settings(Request $request, Workspace $workspace, string $site, WorkspaceProjectAccess $access, WorkspaceAnalyticsAdministrationProviderRegistry $providers): View
    {
        $user = $this->authorizeWorkspace($request, $workspace, $access);
        $siteProvider = $providers->sites();
        abort_if($siteProvider === null, 503);
        $snapshot = $siteProvider->snapshot($user, $workspace);
        $siteData = $siteProvider->siteSummary($user, $workspace, $site);
        abort_if($siteData === null, 404);
        abort_unless($siteData->canManage, 403);

        return view('core::workspaces.analytics.settings', $this->pageData($user, $workspace) + compact('siteData', 'snapshot', 'workspace'));
    }

    public function update(UpdateAnalyticsSiteRequest $request, Workspace $workspace, string $site, WorkspaceProjectAccess $access, WorkspaceAnalyticsAdministrationProviderRegistry $providers): RedirectResponse
    {
        $user = $this->authorizeWorkspace($request, $workspace, $access);
        $provider = $providers->sites();
        abort_if($provider === null, 503);
        $values = $request->validated();
        $domains = collect(preg_split('/[,\r\n]+/', (string) $values['domains']) ?: [])->map(fn (string $domain): string => trim($domain))->filter()->values()->all();
        $excluded = collect(preg_split('/\R/', (string) ($values['excluded_paths'] ?? '')) ?: [])->map(fn (string $path): string => trim($path))->filter()->values()->all();
        $provider->update($user, $workspace, $site, new AnalyticsSiteSettings(
            (string) $values['name'], $domains, (string) $values['timezone'], $excluded,
            (bool) ($values['collection_enabled'] ?? false), (bool) ($values['collection_paused'] ?? false),
        ));

        return to_route('core.workspace.analytics.sites.settings', [$workspace, $site])->with('status', __('Analytics site settings saved.'));
    }

    public function deleteSite(Request $request, Workspace $workspace, string $site, WorkspaceProjectAccess $access, WorkspaceAnalyticsAdministrationProviderRegistry $providers): RedirectResponse
    {
        $user = $this->authorizeWorkspace($request, $workspace, $access);
        $confirmation = $request->validate(['confirmation' => ['required', 'string', 'max:255']])['confirmation'];
        $provider = $providers->sites();
        abort_if($provider === null, 503);
        $outcome = $provider->delete($user, $workspace, $site, (string) $confirmation);

        return $outcome->completed()
            ? to_route('core.workspace.analytics.sites.index', $workspace)->with('status', __('Analytics site deleted.'))
            : to_route('core.workspace.analytics.sites.deletion-status', [$workspace, $outcome->requestId]);
    }

    public function siteDeletionStatus(Request $request, Workspace $workspace, string $requestId, WorkspaceProjectAccess $access, WorkspaceAnalyticsAdministrationProviderRegistry $providers): View
    {
        $user = $this->authorizeWorkspace($request, $workspace, $access);
        $provider = $providers->sites();
        abort_if($provider === null, 503);
        $outcome = $provider->deletionStatus($user, $workspace, $requestId);

        return view('core::workspaces.analytics.deletion', $this->pageData($user, $workspace) + compact('outcome', 'workspace'));
    }

    public function retrySiteDeletion(Request $request, Workspace $workspace, string $requestId, WorkspaceProjectAccess $access, WorkspaceAnalyticsAdministrationProviderRegistry $providers): RedirectResponse
    {
        $user = $this->authorizeWorkspace($request, $workspace, $access);
        $provider = $providers->sites();
        abort_if($provider === null, 503);
        $outcome = $provider->retryDeletion($user, $workspace, $requestId);

        return $outcome->completed()
            ? to_route('core.workspace.analytics.sites.index', $workspace)->with('status', __('Analytics site deleted.'))
            : to_route('core.workspace.analytics.sites.deletion-status', [$workspace, $outcome->requestId]);
    }

    public function goals(Request $request, Workspace $workspace, string $site, WorkspaceProjectAccess $access, WorkspaceAnalyticsAdministrationProviderRegistry $providers): View
    {
        $user = $this->authorizeWorkspace($request, $workspace, $access);
        $provider = $providers->goals();
        abort_if($provider === null, 503);
        $snapshot = $provider->snapshot($user, $workspace, $site);
        abort_if($snapshot === null, 404);

        return view('core::workspaces.analytics.goals', $this->pageData($user, $workspace) + compact('snapshot', 'workspace'));
    }

    public function createGoal(SaveAnalyticsGoalRequest $request, Workspace $workspace, string $site, WorkspaceProjectAccess $access, WorkspaceAnalyticsAdministrationProviderRegistry $providers): RedirectResponse
    {
        $user = $this->authorizeWorkspace($request, $workspace, $access);
        $provider = $providers->goals();
        abort_if($provider === null, 503);
        $provider->create($user, $workspace, $site, $this->goalInput($request));

        return to_route('core.workspace.analytics.goals.index', [$workspace, $site])->with('status', __('Analytics goal created.'));
    }

    public function updateGoal(SaveAnalyticsGoalRequest $request, Workspace $workspace, string $site, string $goal, WorkspaceProjectAccess $access, WorkspaceAnalyticsAdministrationProviderRegistry $providers): RedirectResponse
    {
        $user = $this->authorizeWorkspace($request, $workspace, $access);
        $provider = $providers->goals();
        abort_if($provider === null, 503);
        $provider->update($user, $workspace, $site, $goal, $this->goalInput($request));

        return to_route('core.workspace.analytics.goals.index', [$workspace, $site])->with('status', __('Analytics goal updated.'));
    }

    public function deleteGoal(Request $request, Workspace $workspace, string $site, string $goal, WorkspaceProjectAccess $access, WorkspaceAnalyticsAdministrationProviderRegistry $providers): RedirectResponse
    {
        $user = $this->authorizeWorkspace($request, $workspace, $access);
        $provider = $providers->goals();
        abort_if($provider === null, 503);
        $provider->delete($user, $workspace, $site, $goal);

        return to_route('core.workspace.analytics.goals.index', [$workspace, $site])->with('status', __('Analytics goal removed.'));
    }

    public function data(Request $request, Workspace $workspace, WorkspaceProjectAccess $access, WorkspaceAnalyticsAdministrationProviderRegistry $providers): View
    {
        $user = $this->authorizeWorkspace($request, $workspace, $access);
        $provider = $providers->data();
        abort_if($provider === null, 503);
        $snapshot = $provider->snapshot($user, $workspace, $request->query('site'));

        return view('core::workspaces.analytics.data', $this->pageData($user, $workspace) + compact('snapshot', 'workspace'));
    }

    public function requestReport(RequestAnalyticsReportRequest $request, Workspace $workspace, string $site, WorkspaceProjectAccess $access, WorkspaceAnalyticsAdministrationProviderRegistry $providers): RedirectResponse
    {
        $user = $this->authorizeWorkspace($request, $workspace, $access);
        $provider = $providers->data();
        abort_if($provider === null, 503);
        $values = $request->validated();
        $provider->requestReport($user, $workspace, $site, [
            'days' => (int) $values['days'], 'path' => $values['path'] ?? null, 'source' => $values['source'] ?? null,
            'campaign' => $values['campaign'] ?? null, 'device' => $values['device'] ?? null,
        ]);

        return to_route('core.workspace.analytics.data', $workspace)->with('status', __('Analytics CSV export queued.'));
    }

    public function retryReport(Request $request, Workspace $workspace, string $site, string $export, WorkspaceProjectAccess $access, WorkspaceAnalyticsAdministrationProviderRegistry $providers): RedirectResponse
    {
        $user = $this->authorizeWorkspace($request, $workspace, $access);
        $provider = $providers->data();
        abort_if($provider === null, 503);
        $provider->retryReport($user, $workspace, $site, $export);

        return to_route('core.workspace.analytics.data', $workspace)->with('status', __('Analytics export queued again.'));
    }

    public function downloadReport(Request $request, Workspace $workspace, string $site, string $export, WorkspaceProjectAccess $access, WorkspaceAnalyticsAdministrationProviderRegistry $providers): StreamedResponse
    {
        $user = $this->authorizeWorkspace($request, $workspace, $access);
        $provider = $providers->data();
        abort_if($provider === null, 503);

        return $provider->downloadReport($user, $workspace, $site, $export);
    }

    public function workspaceExport(Request $request, Workspace $workspace, WorkspaceProjectAccess $access, WorkspaceAnalyticsAdministrationProviderRegistry $providers): StreamedResponse
    {
        $user = $this->authorizeWorkspace($request, $workspace, $access);
        $provider = $providers->data();
        abort_if($provider === null, 503);

        return $provider->workspaceExport($user, $workspace);
    }

    private function authorizeWorkspace(Request $request, Workspace $workspace, WorkspaceProjectAccess $access): PlatformUser
    {
        $user = $request->user('platform');
        abort_unless($user instanceof PlatformUser, 401);
        $membership = $access->activeMembership($user, $workspace);
        abort_if($membership === null, 404);
        abort_unless($access->hasProductAccess($membership, 'analytics'), 404);

        return $user;
    }

    private function goalInput(SaveAnalyticsGoalRequest $request): AnalyticsGoalInput
    {
        $values = $request->validated();

        return new AnalyticsGoalInput(
            (string) $values['name'], (string) $values['kind'], (string) $values['match_type'],
            (string) $values['match_value'], (bool) ($values['active'] ?? true),
        );
    }

    /** @return array<string, mixed> */
    private function pageData(PlatformUser $user, Workspace $workspace): array
    {
        $workspaces = Workspace::query()->where('status', 'active')->whereNull('archived_at')
            ->whereHas('memberships', fn (Builder $query) => $query->currentlyActive()->where('user_id', $user->getKey()))
            ->orderBy('name')->get();
        $projects = Project::query()->where('workspace_id', $workspace->getKey())->where('status', 'active')->whereNull('archived_at')
            ->whereHas('memberships', fn (Builder $query) => $query->where('user_id', $user->getKey())->where('status', 'active')->whereNull('revoked_at'))
            ->orderBy('name')->limit(30)->get(['id', 'workspace_id', 'name']);
        $contextProjects = $projects->map(fn (Project $project): array => [
            'id' => (string) $project->getKey(), 'name' => $project->name, 'href' => route('core.projects.show', [$workspace, $project]),
        ]);

        return [
            'user' => $user, 'accountUser' => $user, 'workspace' => $workspace, 'currentWorkspace' => $workspace,
            'workspaces' => $workspaces, 'contextProjects' => $contextProjects, 'navigation' => [],
        ];
    }
}
