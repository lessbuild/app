<?php

namespace App\Core\Http\Controllers;

use App\Core\Http\Requests\StoreProjectEnvironmentRequest;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\Workspace;
use App\Core\Services\Identity\ResolvePlatformUser;
use App\Core\Services\Projects\CreateCanonicalEnvironment;
use App\Core\Services\WorkspaceProjectAccess;
use Illuminate\Http\RedirectResponse;

final class ProjectEnvironmentsController
{
    public function store(
        StoreProjectEnvironmentRequest $request,
        Workspace $workspace,
        Project $project,
        ResolvePlatformUser $platformUsers,
        WorkspaceProjectAccess $access,
        CreateCanonicalEnvironment $createEnvironment,
    ): RedirectResponse {
        abort_unless($project->workspace_id === $workspace->getKey(), 404);
        $user = $this->platformUser($request, $platformUsers);
        abort_unless($access->canViewProject($user, $project), 404);
        abort_unless($access->canManageWorkspace($user, $workspace), 403);

        $environment = $createEnvironment->handle(
            $workspace,
            $project,
            $user,
            $request->validated('name'),
            $request->validated('environment_type'),
        );

        return redirect()
            ->to(route('core.projects.show', [$workspace, $project]).'#environment-'.$environment->getKey())
            ->with('success', __('Shared project environment created. Link each application environment to it explicitly.'));
    }

    private function platformUser(StoreProjectEnvironmentRequest $request, ResolvePlatformUser $platformUsers): PlatformUser
    {
        $principal = $request->user('platform') ?? $request->user();
        abort_unless($principal !== null, 401);

        $user = $platformUsers->resolve($principal, 'deployer');
        abort_if($user === null, 403);

        return $user;
    }
}
