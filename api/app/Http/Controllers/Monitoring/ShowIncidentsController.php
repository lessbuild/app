<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Models\Incident;
use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\ProjectIncidentsQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowIncidentsController
{
    /**
     * List the project's open incidents, or (`?status=resolved`) its closed ones.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  ProjectIncidentsQuery  $incidents
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ProjectIncidentsQuery $incidents): JsonResponse
    {
        $status = in_array($request->query('status'), ['open', 'resolved'], true) ? (string) $request->query('status') : 'open';

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'incidents' => array_map(fn (Incident $incident): array => [
                'id' => $incident->id,
                'title' => $incident->title,
                'status' => $incident->status,
                'statusLabel' => __($incident->statusLabel()),
                'openedAt' => $incident->opened_at->toIso8601String(),
                'assignee' => $incident->assignee?->name,
            ], $incidents->handle($project, $status)),
            'status' => $status,
        ]);
    }
}
