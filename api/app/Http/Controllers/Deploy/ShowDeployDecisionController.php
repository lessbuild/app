<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Models\Build;
use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class ShowDeployDecisionController
{
    /**
     * Show a deploy waiting for approval with one decision ready to confirm, as opened from the Approve or Reject
     * button in a Slack, Teams or Discord message. Nothing changes until the form is sent.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Build  $build
     * @param  ProjectOverviewQuery  $overview
     * @return View
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Build $build, ProjectOverviewQuery $overview): View
    {
        $build->loadMissing(['repository', 'environment', 'requester']);

        return view('deploy.decide', [
            'overview' => $overview->handle($project, $user),
            'build' => $build,
            'decision' => $request->query('decision') === 'reject' ? 'reject' : 'approve',
            'canApprove' => $user->can('approve', $build),
            'reason' => $build->requested_by === $user->id ? __('Someone else has to approve your deploy.') : __('You can’t approve deploys here.'),
        ]);
    }
}
