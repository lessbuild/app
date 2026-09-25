<?php

namespace App\Modules\Monitor\Http\Middleware;

use App\Modules\Monitor\Services\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireWorkspace
{
    public function __construct(private readonly CurrentWorkspace $currentWorkspace) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('monitor.settings.billing*') && ! $request->user()->workspaces()->exists()) {
            return to_route('monitor.workspaces.create');
        }

        $this->currentWorkspace->get();

        return $next($request);
    }
}
