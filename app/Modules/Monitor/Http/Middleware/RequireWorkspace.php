<?php

namespace App\Modules\Monitor\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireWorkspace
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()->workspaces()->exists()) {
            return to_route('monitor.workspaces.create');
        }

        return $next($request);
    }
}
