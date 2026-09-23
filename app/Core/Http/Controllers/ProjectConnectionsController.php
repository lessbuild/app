<?php

namespace App\Core\Http\Controllers;

use App\Core\Http\Requests\StoreProjectConnectionRequest;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectConnection;
use App\Core\Models\ProjectConnectionDelivery;
use App\Core\Models\Workspace;
use App\Core\Services\Connections\RetryProjectConnectionDelivery;
use App\Core\Services\Identity\ResolvePlatformUser;
use App\Core\Services\Projects\CreateProjectConnection;
use App\Core\Services\Projects\DisconnectProjectConnection;
use App\Core\Services\Projects\SetProjectConnectionAutomationState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ProjectConnectionsController
{
    public function store(
        StoreProjectConnectionRequest $request,
        Workspace $workspace,
        Project $project,
        ResolvePlatformUser $platformUsers,
        CreateProjectConnection $createConnection,
    ): RedirectResponse {
        abort_unless($project->workspace_id === $workspace->getKey(), 404);
        $user = $this->platformUser($request, $platformUsers);
        $createConnection->handle(
            user: $user,
            project: $project,
            sourceResourceId: $request->validated('source_resource_id'),
            targetResourceId: $request->validated('target_resource_id'),
            capabilities: $request->validated('capabilities'),
        );

        return redirect()
            ->route('core.projects.show', [$workspace, $project])
            ->with('success', __('Connection saved and awaiting workflow setup.'));
    }

    public function destroy(
        Request $request,
        Workspace $workspace,
        Project $project,
        ProjectConnection $connection,
        ResolvePlatformUser $platformUsers,
        DisconnectProjectConnection $disconnectConnection,
    ): RedirectResponse {
        abort_unless($project->workspace_id === $workspace->getKey(), 404);
        $user = $this->platformUser($request, $platformUsers);
        $disconnectConnection->handle($user, $project, $connection);

        return redirect()
            ->route('core.projects.show', [$workspace, $project])
            ->with('success', __('Connection disconnected. Existing product history was kept.'));
    }

    public function retry(
        Request $request,
        Workspace $workspace,
        Project $project,
        ProjectConnection $connection,
        ProjectConnectionDelivery $delivery,
        ResolvePlatformUser $platformUsers,
        RetryProjectConnectionDelivery $retryDelivery,
    ): RedirectResponse {
        abort_unless($project->workspace_id === $workspace->getKey(), 404);
        $user = $this->platformUser($request, $platformUsers);
        $retried = $retryDelivery->handle($user, $project, $connection, $delivery);

        return redirect()
            ->route('core.projects.show', [$workspace, $project])
            ->with($retried ? 'success' : 'info', $retried
                ? __('This workflow step was queued for retry.')
                : __('This step is no longer retryable, or automation is paused.'));
    }

    public function automation(
        Request $request,
        Workspace $workspace,
        Project $project,
        ProjectConnection $connection,
        ResolvePlatformUser $platformUsers,
        SetProjectConnectionAutomationState $automation,
    ): RedirectResponse {
        abort_unless($project->workspace_id === $workspace->getKey(), 404);
        $user = $this->platformUser($request, $platformUsers);
        $paused = (bool) $request->validate(['paused' => ['required', 'boolean']])['paused'];
        $automation->handle($user, $project, $connection, $paused);

        return redirect()
            ->route('core.projects.show', [$workspace, $project])
            ->with('success', $paused
                ? __('Automation paused. The connection and delivery history were kept.')
                : __('Automation resumed. Queued updates can continue.'));
    }

    private function platformUser(Request $request, ResolvePlatformUser $platformUsers): PlatformUser
    {
        $principal = $request->user();
        abort_unless($principal !== null, 401);

        $platformUser = $platformUsers->resolve($principal, 'deployer');
        abort_if($platformUser === null, 403);

        return $platformUser;
    }
}
