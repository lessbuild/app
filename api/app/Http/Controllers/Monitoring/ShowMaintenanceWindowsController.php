<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Models\MaintenanceWindow;
use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowMaintenanceWindowsController
{
    /**
     * List the account's maintenance windows (upcoming, in progress, and those that ended in the last 30 days).
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview): JsonResponse
    {
        return response()->json([
            'overview' => $overview->handle($project, $user),
            'accountName' => $project->account->name,
            'windows' => MaintenanceWindow::query()->where('account_id', $project->account_id)->where('ends_at', '>', now('UTC')->subDays(30))->orderByDesc('starts_at')->get()
                ->map(fn (MaintenanceWindow $window): array => [
                    'id' => $window->id,
                    'name' => $window->name,
                    'reason' => $window->reason,
                    'startsAt' => $window->starts_at->toIso8601String(),
                    'endsAt' => $window->ends_at->toIso8601String(),
                    'state' => $window->ends_at->isPast() ? 'past' : ($window->starts_at->isPast() ? 'active' : 'upcoming'),
                ])->values(),
            'canManage' => $user->can('create', [MaintenanceWindow::class, $project]),
        ]);
    }
}
