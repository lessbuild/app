<?php

namespace App\Modules\Deployer\Http\Middleware;

use App\Core\Services\Auth\ProductAuthentication;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\PersonalOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCurrentOrganization
{
    /**
     * Resolve or create the personal workspace through the shared organization service.
     */
    public function __construct(
        private readonly PersonalOrganization $organizations,
        private readonly ProductAuthentication $authentication,
    ) {}

    /**
     * Ensure an authenticated request user has a current organization before continuing the pipeline.
     *
     * @param  Closure(Request): Response  $next  The remaining HTTP middleware pipeline.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            if ($this->authentication->usesCoreAuthority('deployer') && $request->query->has('organization_id')) {
                return $next($request);
            }

            $this->organizations->ensure($user);
        }

        return $next($request);
    }
}
