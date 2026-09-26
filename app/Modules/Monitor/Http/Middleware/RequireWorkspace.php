<?php

namespace App\Modules\Monitor\Http\Middleware;

use App\Modules\Monitor\Services\Core\MonitorDeletionFence;
use App\Modules\Monitor\Services\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class RequireWorkspace
{
    public function __construct(private readonly CurrentWorkspace $currentWorkspace) {}

    public function handle(Request $request, Closure $next): Response
    {
        MonitorDeletionFence::assertUserActive($request->user()?->getAuthIdentifier());

        if (! $request->routeIs('monitor.settings.billing*') && ! $request->user()->workspaces()->exists()) {
            return to_route('monitor.workspaces.create');
        }

        $workspace = $this->currentWorkspace->get();
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            MonitorDeletionFence::assertWorkspaceActive($workspace->getKey());

            return $next($request);
        }

        return DB::connection('monitor')->transaction(function () use ($next, $request, $workspace): Response {
            abort_if(MonitorDeletionFence::lockWorkspace($workspace->getKey()), 410, 'This Monitor workspace is being deleted.');

            return $next($request);
        });
    }
}
