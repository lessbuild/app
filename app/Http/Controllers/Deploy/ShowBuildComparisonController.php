<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Models\Build;
use App\Models\Project;
use App\Models\User;
use App\Queries\Deploy\BuildComparisonQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class ShowBuildComparisonController
{
    /**
     * Compare a deploy with another from the same repository: the one chosen in ?with=, or else the last successful
     * deploy before it.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Build  $build
     * @param  ProjectOverviewQuery  $overview
     * @param  BuildComparisonQuery  $comparison
     * @return View
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Build $build, ProjectOverviewQuery $overview, BuildComparisonQuery $comparison): View
    {
        $sameRepository = fn () => Build::query()->where('repository_id', $build->repository_id)->whereKeyNot($build->id);
        $baseline = is_numeric($request->query('with'))
            ? $sameRepository()->findOrFail((int) $request->query('with'))
            : $sameRepository()->where('id', '<', $build->id)->where('status', Build::STATUS_SUCCEEDED)->latest('id')->first();

        return view('deploy.build-compare', [
            'overview' => $overview->handle($project, $user),
            'build' => $build,
            'comparison' => $baseline !== null ? $comparison->handle($build, $baseline) : null,
            'candidates' => $sameRepository()->latest('id')->limit(30)->get(['id', 'status', 'revision', 'commit_message', 'created_at']),
        ]);
    }
}
