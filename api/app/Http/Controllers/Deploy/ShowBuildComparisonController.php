<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Data\Deploy\BuildChange;
use App\Models\Build;
use App\Models\Project;
use App\Models\User;
use App\Queries\Deploy\BuildComparisonQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `GET /api/app/projects/{project}/deploy/builds/{build}/compare?with=`. */
final class ShowBuildComparisonController
{
    /**
     * Compare a deploy with another of the same repository (the last good one before it, unless `with` names one):
     * both side by side, the time difference, the settings that changed between them, and the code diff's address.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Build  $build
     * @param  ProjectOverviewQuery  $overview
     * @param  BuildComparisonQuery  $comparison
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Build $build, ProjectOverviewQuery $overview, BuildComparisonQuery $comparison): JsonResponse
    {
        $sameRepository = fn () => Build::query()->where('repository_id', $build->repository_id)->whereKeyNot($build->id);
        $baseline = is_numeric($request->query('with'))
            ? $sameRepository()->findOrFail((int) $request->query('with'))
            : $sameRepository()->where('id', '<', $build->id)->where('status', Build::STATUS_SUCCEEDED)->latest('id')->first();
        $result = $baseline !== null ? $comparison->handle($build, $baseline) : null;
        $build->load(['repository', 'website', 'environment', 'requester']);
        $baseline?->load(['environment', 'requester']);

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'build' => $this->describe($build) + ['repository' => $build->repository->name, 'website' => $build->website->name],
            'baseline' => $baseline === null ? null : $this->describe($baseline),
            'comparison' => $result === null ? null : [
                'durationDelta' => $result->durationDelta,
                'compareUrl' => $result->compareUrl,
                'snapshotsAvailable' => $result->snapshotsAvailable,
                'changes' => array_map(fn (BuildChange $change): array => (array) $change, $result->changes),
            ],
            'candidates' => $sameRepository()->latest('id')->limit(30)->get(['id', 'status', 'revision', 'commit_message', 'created_at'])->map(fn (Build $candidate): array => [
                'id' => $candidate->id, 'status' => $candidate->status, 'revision' => $candidate->shortRevision(), 'commitMessage' => $candidate->commit_message,
            ])->values(),
        ]);
    }

    /**
     * Describe one side of the comparison.
     *
     * @param  Build  $build
     * @return array<string, mixed>
     */
    private function describe(Build $build): array
    {
        return [
            'id' => $build->id,
            'status' => $build->status,
            'revision' => $build->revision,
            'shortRevision' => $build->shortRevision(),
            'commitMessage' => $build->commit_message,
            'requester' => $build->requester?->name,
            'trigger' => $build->trigger_source,
            'environment' => $build->environment?->name,
            'startedAt' => $build->started_at?->toIso8601String(),
            'seconds' => $build->started_at !== null && $build->finished_at !== null ? (int) $build->started_at->diffInSeconds($build->finished_at, true) : null,
            'note' => $build->operator_note,
            'failure' => $build->failure_message,
        ];
    }
}
