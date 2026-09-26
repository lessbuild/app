<?php

namespace App\Modules\Monitor\Http\Middleware;

use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Services\Core\MonitorDeletionFence;
use App\Modules\Monitor\Services\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApplicationWorkspace
{
    public function __construct(private readonly CurrentWorkspace $currentWorkspace) {}

    public function handle(Request $request, Closure $next): Response
    {
        $application = $request->route('application');

        if ($application !== null) {
            abort_unless($application instanceof Application && $application->workspace_id === $this->currentWorkspace->get()->id, 404);
            MonitorDeletionFence::assertWorkspaceActive($application->workspace_id);
        }

        return $next($request);
    }
}
