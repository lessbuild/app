<?php

namespace App\Modules\Monitor\Services;

use App\Core\Models\PlatformUser;
use App\Core\Services\Auth\ProductAuthentication;
use App\Core\Services\Identity\ProductWorkspaceAccess;
use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;

final class CurrentWorkspace
{
    public function __construct(
        private readonly Request $request,
        private readonly ProductAuthentication $authentication,
        private readonly ProductWorkspaceAccess $workspaceAccess,
    ) {}

    public function get(): Workspace
    {
        $user = $this->request->user();
        abort_unless($user, 401);

        if ($this->isCoreBillingRoute()) {
            return $this->billingWorkspace();
        }

        $application = $this->request->route('application');
        $environment = $this->request->route('environment');

        if (! $application instanceof Application && $environment instanceof Environment) {
            $application = $environment->application;
        }

        if ($application instanceof Application) {
            $workspace = $user->workspaces()->whereKey($application->workspace_id)->first();
            abort_unless($workspace, 404);

            return $this->ensureProductAccess($workspace);
        }

        $routeWorkspace = $this->request->route('workspace');

        if ($routeWorkspace instanceof Workspace) {
            $workspace = $user->workspaces()->whereKey($routeWorkspace->getKey())->first();
            abort_unless($workspace, 404);
            $this->request->session()->put('workspace_id', $workspace->getKey());

            return $this->ensureProductAccess($workspace);
        }

        $requestedWorkspaceId = $this->request->query('workspace_id');

        if ($requestedWorkspaceId !== null) {
            abort_unless(is_string($requestedWorkspaceId) || is_int($requestedWorkspaceId), 404);

            $workspace = $user->workspaces()->whereKey($requestedWorkspaceId)->first();
            abort_unless($workspace, 404);
            $this->request->session()->put('workspace_id', $workspace->getKey());

            return $this->ensureProductAccess($workspace);
        }

        $workspace = $user->workspaces()
            ->whereKey($this->request->session()->get('workspace_id'))
            ->first();

        if ($workspace === null) {
            $workspace = $user->workspaces()->orderBy('workspaces.id')->first();
        }

        abort_unless($workspace, 403, 'Create a workspace to continue.');
        $this->request->session()->put('workspace_id', $workspace->id);

        return $this->ensureProductAccess($workspace);
    }

    public function authorizeBilling(Workspace $workspace): void
    {
        if ($this->authentication->usesCoreAuthority('monitor')) {
            $platformUser = $this->request->attributes->get('platform_user');

            abort_unless(
                $platformUser instanceof PlatformUser
                    && $this->workspaceAccess->canManageBilling($platformUser, 'monitor', 'workspace', $workspace->getKey()),
                403,
            );

            return;
        }

        Gate::authorize('billing', $workspace);
    }

    private function isCoreBillingRoute(): bool
    {
        return $this->authentication->usesCoreAuthority('monitor')
            && $this->request->routeIs('monitor.settings.billing*');
    }

    private function billingWorkspace(): Workspace
    {
        $requestedId = $this->request->query('workspace_id');
        $workspaceId = $requestedId
            ?? $this->request->session()->get('monitor_billing_workspace_id')
            ?? $this->request->session()->get('workspace_id');

        abort_unless(
            (is_string($workspaceId) || is_int($workspaceId)) && ctype_digit((string) $workspaceId),
            404,
        );

        $workspace = Workspace::query()->find($workspaceId);
        abort_unless($workspace instanceof Workspace, 404);
        $platformUser = $this->request->attributes->get('platform_user');

        abort_unless(
            $platformUser instanceof PlatformUser
                && $this->workspaceAccess->canManageBilling($platformUser, 'monitor', 'workspace', $workspace->getKey()),
            403,
            'Only a Buildpusher workspace owner or billing manager can manage this Monitor plan.',
        );

        $this->request->session()->put('monitor_billing_workspace_id', $workspace->getKey());
        URL::defaults(['workspace_id' => $workspace->getKey()]);

        return $workspace;
    }

    private function ensureProductAccess(Workspace $workspace): Workspace
    {
        if (! $this->authentication->usesCoreAuthority('monitor')) {
            return $workspace;
        }

        $platformUser = $this->request->attributes->get('platform_user');

        abort_unless(
            $platformUser instanceof PlatformUser
                && $this->workspaceAccess->allows($platformUser, 'monitor', 'workspace', $workspace->getKey()),
            403,
            'This Monitor workspace is not available to your Buildpusher account.',
        );

        return $workspace;
    }
}
