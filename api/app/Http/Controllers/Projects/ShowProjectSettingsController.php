<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Enums\EnvironmentKind;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Queries\Projects\ProjectInfrastructureQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `GET /api/app/projects/{project}/settings`. */
final class ShowProjectSettingsController
{
    /**
     * Return the project's settings: its details, environments, the kinds a new environment can be, and the servers and
     * websites that deleting the project would destroy, so the delete form can warn about them.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $query
     * @param  ProjectInfrastructureQuery  $infrastructure
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $query, ProjectInfrastructureQuery $infrastructure): JsonResponse
    {
        abort_unless($user->can('update', $project), 403);

        return response()->json([
            'overview' => $query->handle($project, $user),
            'kinds' => array_map(
                fn (EnvironmentKind $kind): array => ['value' => $kind->value, 'label' => $kind->label()],
                array_filter(EnvironmentKind::cases(), fn (EnvironmentKind $kind): bool => $kind !== EnvironmentKind::Preview),
            ),
            'teardown' => [
                'servers' => $infrastructure->exclusiveServers($project)
                    ->map(fn (Server $server): array => ['name' => $server->label(), 'size' => $server->size, 'ip' => $server->public_ip])
                    ->values(),
                'websites' => $infrastructure->websites($project)
                    ->map(fn (Website $website): array => ['url' => $website->url])
                    ->values(),
            ],
        ]);
    }
}
