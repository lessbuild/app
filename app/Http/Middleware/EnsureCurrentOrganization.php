<?php

namespace App\Http\Middleware;

use App\Services\PersonalOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCurrentOrganization
{
    public function __construct(private readonly PersonalOrganization $organizations) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()) {
            $this->organizations->ensure($request->user());
        }

        return $next($request);
    }
}
