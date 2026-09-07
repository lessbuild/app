<?php

namespace App\Http\Middleware;

use App\Services\PersonalOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCurrentOrganization
{
    /**
     * Resolve or create the personal workspace through the shared organization service.
     */
    public function __construct(private readonly PersonalOrganization $organizations) {}

    /**
     * Ensure an authenticated request user has a current organization before continuing the pipeline.
     *
     * @param  Closure(Request): Response  $next  The remaining HTTP middleware pipeline.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()) {
            $this->organizations->ensure($request->user());
        }

        return $next($request);
    }
}
