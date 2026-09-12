<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Entitlements;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureControlPlaneAccess
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly EnforceOrganizationSecurity $security,
    ) {}

    /**
     * Enforce API entitlement, workspace network policy and the route's Sanctum ability before request validation.
     *
     * @param  Closure(Request): Response  $next  The downstream API request pipeline.
     * @param  string  $ability  The required token ability, such as read, deploy or manage.
     */
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        /** @var User $user */
        $user = $request->user();
        $this->entitlements->enforce($user, 'api');
        $ranges = $user->currentOrganization?->allowed_ip_ranges ?? [];
        abort_if($ranges !== [] && ! collect($ranges)->contains(fn (string $range): bool => $this->security->contains($range, (string) $request->ip())), 403, 'This network is not allowed by the workspace security policy.');
        abort_unless($user->tokenCan($ability), 403, "Token lacks the {$ability} ability.");

        return $next($request);
    }
}
