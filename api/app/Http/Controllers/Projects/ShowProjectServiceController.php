<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Data\Projects\ServiceOption;
use App\Models\Project;
use App\Models\User;
use App\Platform\ServiceRegistry;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `GET /api/app/projects/{project}/services/{service}`. */
final class ShowProjectServiceController
{
    /**
     * Return a service's page in the project: while it's off, what it does and whether the person can turn it on;
     * once it's on, where its own pages start.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  string  $service
     * @param  ProjectOverviewQuery  $query
     * @param  ServiceRegistry  $services
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, string $service, ProjectOverviewQuery $query, ServiceRegistry $services): JsonResponse
    {
        $definition = $services->find($service) ?? abort(404);
        abort_unless($user->can('useService', [$project, $service]), 403);
        $landing = $definition->navItems($project->id)[0]->url ?? null;
        if ($project->hasService($service) && $landing !== null) {
            $parts = parse_url($landing);

            return response()->json(['redirect' => ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '')]);
        }

        return response()->json([
            'overview' => $query->handle($project, $user),
            'service' => ServiceOption::from($definition),
            'enabled' => $project->hasService($service),
            'canManage' => $user->can('manageService', [$project, $service]),
        ]);
    }
}
