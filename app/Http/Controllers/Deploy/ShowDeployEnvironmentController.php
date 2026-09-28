<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Models\Environment;
use App\Models\Project;
use App\Models\Recipe;
use App\Models\User;
use App\Queries\Deploy\EnvironmentAutomationQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Support\PageTabs;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class ShowDeployEnvironmentController
{
    /**
     * Show an environment's deploy settings, in tabs: controls, how deploys run, variables, workers, resources, and
     * automation (schedules, tasks and hibernation), and recipes.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Environment  $environment
     * @param  ProjectOverviewQuery  $overview
     * @param  EnvironmentAutomationQuery  $automation
     * @return View
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Environment $environment, ProjectOverviewQuery $overview, EnvironmentAutomationQuery $automation): View
    {
        $tabs = array_filter(['controls' => __('Controls'), 'settings' => __('How deploys run'), 'variables' => __('Variables'), 'processes' => __('Workers'), 'resources' => __('Resources'), 'automation' => __('Automation'), 'recipes' => __('Recipes')]);

        return view('deploy.environment', [
            'tabs' => $tabs,
            'tab' => PageTabs::current($request->query('tab'), $tabs),
            'overview' => $overview->handle($project, $user),
            'environment' => $environment->load(['variables' => fn ($query) => $query->orderBy('key'), 'processes', 'resources']),
            'blockReason' => $environment->deploymentBlockReason(),
            'canManage' => $user->can('configureDeploy', $environment),
            ...$automation->handle($environment),
            'environmentRecipes' => $environment->recipes()->with('recipe')->get(),
            'libraryRecipes' => Recipe::query()->where('account_id', $project->account_id)->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
