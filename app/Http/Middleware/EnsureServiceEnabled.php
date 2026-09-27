<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Project;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** `service:analytics` on a project's service routes: 403 without access, the enable page when the service is off. */
final class EnsureServiceEnabled
{
    public function handle(Request $request, Closure $next, string $service): Response
    {
        $project = $request->route('project');
        $user = $request->user();
        abort_unless($project instanceof Project && $user instanceof User && $user->can('useService', [$project, $service]), 403);

        if (! $project->hasService($service)) {
            return to_route('projects.services.show', [$project, $service]);
        }

        return $next($request);
    }
}
