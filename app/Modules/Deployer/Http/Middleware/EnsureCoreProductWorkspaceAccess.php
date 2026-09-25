<?php

namespace App\Modules\Deployer\Http\Middleware;

use App\Core\Models\PlatformUser;
use App\Core\Services\Auth\ProductAuthentication;
use App\Core\Services\Identity\ProductWorkspaceAccess;
use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCoreProductWorkspaceAccess
{
    public function __construct(
        private readonly ProductAuthentication $authentication,
        private readonly ProductWorkspaceAccess $workspaceAccess,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->authentication->usesCoreAuthority('deployer')) {
            return $next($request);
        }

        if ($request->routeIs('logout', 'account.*')) {
            return $next($request);
        }

        $user = $request->user();
        $platformUser = $request->attributes->get('platform_user');

        abort_unless($user instanceof User && $platformUser instanceof PlatformUser, 403);

        $organization = $request->routeIs('organizations.switch')
            ? $request->route('organization')
            : $user->currentOrganization;

        if (! $organization instanceof Organization && is_string($organization)) {
            $organization = Organization::query()->find($organization);
        }

        abort_unless($organization instanceof Organization, 403);

        $hasWorkspaceAccess = $request->routeIs('billing.*')
            ? $this->workspaceAccess->canManageBilling($platformUser, 'deployer', 'organization', $organization->getKey())
            : $this->workspaceAccess->allows($platformUser, 'deployer', 'organization', $organization->getKey());

        abort_unless(
            $hasWorkspaceAccess,
            403,
            $request->routeIs('billing.*')
                ? 'Only a Buildpusher workspace owner or billing manager can manage this Deployer plan.'
                : 'This Deployer workspace is not available to your Buildpusher account.',
        );

        return $next($request);
    }
}
