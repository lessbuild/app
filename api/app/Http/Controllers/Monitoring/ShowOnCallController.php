<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Models\OnCallOverride;
use App\Models\OnCallSchedule;
use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\AlertDestinationsQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Monitoring\OnCall;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowOnCallController
{
    /**
     * List the account's on-call rotations: who's on call now, the next turns, and cover arranged ahead.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  AlertDestinationsQuery  $destinations
     * @param  OnCall  $onCall
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, AlertDestinationsQuery $destinations, OnCall $onCall): JsonResponse
    {
        $schedules = OnCallSchedule::query()->where('account_id', $project->account_id)
            ->with(['members', 'overrides' => fn ($query) => $query->where('ends_at', '>', now())->with('user')->orderBy('starts_at')])->orderBy('name')->get();

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'schedules' => $schedules->map(fn (OnCallSchedule $schedule): array => [
                'id' => $schedule->id,
                'name' => $schedule->name,
                'rotation' => $schedule->rotation,
                'handoffDay' => $schedule->handoff_day ?? 1,
                'handoffTime' => $schedule->handoff_time,
                'timezone' => $schedule->timezone,
                'startsOn' => $schedule->starts_on->format('Y-m-d'),
                'memberIds' => $schedule->members->pluck('id')->map(fn ($id): string => (string) $id)->values(),
                'now' => $onCall->current($schedule)?->name,
                'upcoming' => array_map(fn (array $shift): array => ['user' => $shift['user']?->name, 'starts' => $shift['starts']->toIso8601String(), 'ends' => $shift['ends']->toIso8601String()], $onCall->upcoming($schedule, 4)),
                'overrides' => $schedule->overrides->map(fn (OnCallOverride $override): array => [
                    'id' => $override->id, 'user' => $override->user->name, 'starts' => $override->starts_at->toIso8601String(), 'ends' => $override->ends_at->toIso8601String(),
                ])->values(),
            ])->values(),
            'members' => array_map(fn (User $member): array => ['value' => (string) $member->id, 'label' => $member->name], $destinations->recipients($project->account_id)),
            'canManage' => $user->can('create', [OnCallSchedule::class, $project]),
        ]);
    }
}
