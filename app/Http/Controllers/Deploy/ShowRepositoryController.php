<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Queries\Deploy\RepositoryFormQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Support\PageTabs;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class ShowRepositoryController
{
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Repository $repository, ProjectOverviewQuery $overview, RepositoryFormQuery $form): View
    {
        $tabs = array_filter(['deploys' => __('Deploys'), 'webhook' => __('Push deploys'), 'settings' => $user->can('update', $repository) ? __('Settings') : null]);

        return view('deploy.repository', [
            'tabs' => $tabs,
            'tab' => PageTabs::current($request->query('tab'), $tabs),
            'overview' => $overview->handle($project, $user),
            'repository' => $repository->load(['website.server', 'environment', 'provider']),
            'builds' => $repository->builds()->with(['requester'])->latest('id')->limit(30)->get(),
            'deliveries' => $repository->webhookDeliveries()->latest('id')->limit(10)->get(),
            'canDeploy' => $user->can('deploy', $repository),
            'canManage' => $user->can('update', $repository),
            ...$form->handle($project),
        ]);
    }
}
