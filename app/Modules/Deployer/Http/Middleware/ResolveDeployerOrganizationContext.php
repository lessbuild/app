<?php

namespace App\Modules\Deployer\Http\Middleware;

use App\Core\Models\PlatformUser;
use App\Core\Services\Auth\ProductAuthentication;
use App\Core\Services\Identity\ProductWorkspaceAccess;
use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/** Apply an explicitly linked organization to this request without changing the saved selection. */
final class ResolveDeployerOrganizationContext
{
    public function __construct(
        private readonly ProductAuthentication $authentication,
        private readonly ProductWorkspaceAccess $workspaceAccess,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->query->has('organization_id') || $request->routeIs('organizations.switch', 'logout', 'account.*')) {
            return $next($request);
        }

        $user = $request->user();
        $organizationId = $request->query('organization_id');

        abort_unless($user instanceof User, 404);
        abort_unless(
            (is_string($organizationId) || is_int($organizationId))
                && ctype_digit((string) $organizationId)
                && (int) $organizationId > 0,
            404,
        );

        $organization = Organization::query()->find($organizationId);
        abort_unless($organization instanceof Organization, 404);

        $coreAuthority = $this->authentication->usesCoreAuthority('deployer');
        $coreBillingRoute = $coreAuthority && $request->routeIs('billing.*');

        if ($coreBillingRoute) {
            $platformUser = $request->attributes->get('platform_user');
            abort_unless(
                $platformUser instanceof PlatformUser
                    && $this->workspaceAccess->canManageBilling($platformUser, 'deployer', 'organization', $organization->getKey()),
                403,
            );
        } else {
            abort_unless($organization->roleFor($user) !== null, 404);

            if ($coreAuthority) {
                $platformUser = $request->attributes->get('platform_user');
                abort_unless(
                    $platformUser instanceof PlatformUser
                        && $this->workspaceAccess->allows($platformUser, 'deployer', 'organization', $organization->getKey()),
                    404,
                );
            }
        }

        // This principal is request-local. Do not persist a search destination as the user's default workspace.
        $user->setAttribute('current_organization_id', $organization->getKey());
        $user->setRelation('currentOrganization', $organization);
        URL::defaults(['organization_id' => $organization->getKey()]);

        return $next($request);
    }
}
