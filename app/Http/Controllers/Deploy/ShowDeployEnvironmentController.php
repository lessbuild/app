<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Support\PageTabs;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class ShowDeployEnvironmentController
{
    /**
     * An environment's deploy settings, in tabs: controls, how deploys run, variables, workers and resources.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Environment  $environment
     * @param  ProjectOverviewQuery  $overview
     * @return View
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Environment $environment, ProjectOverviewQuery $overview): View
    {
        $tabs = array_filter(['controls' => __('Controls'), 'settings' => __('How deploys run'), 'variables' => __('Variables'), 'processes' => __('Workers'), 'resources' => __('Resources')]);

        return view('deploy.environment', [
            'tabs' => $tabs,
            'tab' => PageTabs::current($request->query('tab'), $tabs),
            'overview' => $overview->handle($project, $user),
            'environment' => $environment->load(['variables' => fn ($query) => $query->orderBy('key'), 'processes', 'resources']),
            'blockReason' => $environment->deploymentBlockReason(),
            'canManage' => $user->can('configureDeploy', $environment),
        ]);
    }
}
