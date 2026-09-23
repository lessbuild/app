<?php

namespace App\Modules\Deployer\Http\Middleware;

use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Apply an explicitly linked organization to this request without changing the saved selection. */
final class ResolveDeployerOrganizationContext
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('servers.show', 'builds.show') || ! $request->query->has('organization_id')) {
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
        abort_unless($organization?->roleFor($user) !== null, 404);

        // This principal is request-local. Do not persist a search destination as the user's default workspace.
        $user->setAttribute('current_organization_id', $organization->getKey());
        $user->setRelation('currentOrganization', $organization);

        return $next($request);
    }
}
