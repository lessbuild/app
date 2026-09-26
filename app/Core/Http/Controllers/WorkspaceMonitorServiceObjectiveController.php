<?php

namespace App\Core\Http\Controllers;

use App\Core\Http\Requests\ArchiveWorkspaceMonitorServiceObjectiveRequest;
use App\Core\Http\Requests\SaveWorkspaceMonitorServiceObjectiveRequest;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\Workspace;
use App\Core\Services\WorkspaceMonitorAdministrationRegistry;
use App\Core\Services\WorkspaceProjectAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class WorkspaceMonitorServiceObjectiveController
{
    public function index(Request $request, Workspace $workspace, WorkspaceProjectAccess $access, WorkspaceMonitorAdministrationRegistry $providers): Response
    {
        [$user, $workspaces] = $this->coreContext($request, $workspace, $access);
        $filters = $request->validate([
            'slo_search' => ['nullable', 'string', 'max:100'],
            'slo_page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);
        $provider = $providers->serviceObjectives();
        abort_if($provider === null, 404);
        $snapshot = $provider->snapshot($user, $workspace, array_filter($filters, fn ($value) => $value !== null && $value !== ''));
        abort_if($snapshot === null, 404);

        return response()->view('core::workspaces.monitor.service-objectives', $this->viewContext($user, $workspace, $workspaces, $access) + [
            'items' => $snapshot->items,
            'environments' => $snapshot->environments,
            'canManage' => $snapshot->canManage,
        ])->withHeaders(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    public function store(SaveWorkspaceMonitorServiceObjectiveRequest $request, Workspace $workspace, WorkspaceMonitorAdministrationRegistry $providers): RedirectResponse
    {
        $user = $this->requestUser($request);
        $provider = $providers->serviceObjectives();
        abort_if($provider === null, 404);

        try {
            $provider->saveObjective($user, $workspace, null, $request->validated());
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput($request->except(['service', 'route']));
        }

        return to_route('core.workspace.monitor.service-objectives', $workspace)->with('status', 'Service objective created.');
    }

    public function update(SaveWorkspaceMonitorServiceObjectiveRequest $request, Workspace $workspace, WorkspaceMonitorAdministrationRegistry $providers): RedirectResponse
    {
        $user = $this->requestUser($request);
        $provider = $providers->serviceObjectives();
        abort_if($provider === null, 404);
        $data = $request->validated();
        $reference = $data['objective_reference'] ?? null;
        unset($data['objective_reference']);
        abort_unless(is_string($reference) && filled($data['version'] ?? null), 422);

        try {
            $provider->saveObjective($user, $workspace, $reference, $data);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput($request->except(['service', 'route']));
        }

        return to_route('core.workspace.monitor.service-objectives', $workspace)->with('status', 'Service objective updated.');
    }

    public function archive(ArchiveWorkspaceMonitorServiceObjectiveRequest $request, Workspace $workspace, WorkspaceMonitorAdministrationRegistry $providers): RedirectResponse
    {
        $user = $this->requestUser($request);
        $provider = $providers->serviceObjectives();
        abort_if($provider === null, 404);
        $data = $request->validated();
        $provider->archiveObjective($user, $workspace, $data['objective_reference'], $data['version'], in_array($data['confirm_archive'], [true, 1, '1', 'yes', 'on'], true));

        return to_route('core.workspace.monitor.service-objectives', $workspace)->with('status', 'Service objective archived.');
    }

    private function requestUser(Request $request): PlatformUser
    {
        $user = $request->user('platform');
        abort_unless($user instanceof PlatformUser, 401);

        return $user;
    }

    /** @return array{PlatformUser, Collection<int, Workspace>} */
    private function coreContext(Request $request, Workspace $workspace, WorkspaceProjectAccess $access): array
    {
        $user = $this->requestUser($request);
        abort_if($access->activeMembership($user, $workspace) === null, 404);
        $workspaces = Workspace::query()->where('status', 'active')->whereNull('archived_at')
            ->whereHas('memberships', fn ($query) => $query->currentlyActive()->where('user_id', $user->getKey()))
            ->orderBy('name')->get();

        return [$user, $workspaces];
    }

    /** @param Collection<int, Workspace> $workspaces
     * @return array<string, mixed>
     */
    private function viewContext(PlatformUser $user, Workspace $workspace, Collection $workspaces, WorkspaceProjectAccess $access): array
    {
        $contextProjects = $access->accessibleProductProjects($user, $workspace, 'monitor')
            ->orderBy('name')->limit(30)->get(['id', 'workspace_id', 'name'])
            ->map(fn (Project $project): array => [
                'id' => (string) $project->getKey(),
                'name' => $project->name,
                'href' => route('core.projects.show', [$workspace, $project]),
            ]);

        return [
            'user' => $user,
            'workspace' => $workspace,
            'workspaces' => $workspaces,
            'contextProjects' => $contextProjects,
        ];
    }
}
