<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Queries\Deploy\RepositoryFormQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowRepositoryController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, Repository $repository, ProjectOverviewQuery $overview, RepositoryFormQuery $form): View
    {
        return view('deploy.repository', [
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
