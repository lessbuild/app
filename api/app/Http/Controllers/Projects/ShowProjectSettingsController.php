<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Enums\EnvironmentKind;
use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `GET /api/app/projects/{project}/settings`. */
final class ShowProjectSettingsController
{
    /**
     * Return the project's settings: its details, environments, and the kinds a new environment can be.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $query
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $query): JsonResponse
    {
        abort_unless($user->can('update', $project), 403);

        return response()->json([
            'overview' => $query->handle($project, $user),
            'kinds' => array_values(array_map(
                fn (EnvironmentKind $kind): array => ['value' => $kind->value, 'label' => $kind->label()],
                array_filter(EnvironmentKind::cases(), fn (EnvironmentKind $kind): bool => $kind !== EnvironmentKind::Production && $kind !== EnvironmentKind::Preview),
            )),
        ]);
    }
}
