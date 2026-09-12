<?php

namespace App\Http\Middleware;

use App\Services\ControlPlaneAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureControlPlaneAccess
{
    public function __construct(private readonly ControlPlaneAccess $access) {}

    /**
     * Enforce API entitlement, workspace network policy and the route's Sanctum ability before request validation.
     *
     * @param  Closure(Request): Response  $next  The downstream API request pipeline.
     * @param  string  $ability  The required token ability, such as read, deploy or manage.
     */
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $this->access->enforce($request, $ability);

        return $next($request);
    }
}
