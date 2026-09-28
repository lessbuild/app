<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Models\Project;
use App\Models\User;
use App\Queries\Deploy\RepositoryFormQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class CreateRepositoryController
{
    /**
     * The form for connecting a repository to the project.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  RepositoryFormQuery  $form
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, RepositoryFormQuery $form): View
    {
        return view('deploy.repository-form', ['overview' => $overview->handle($project, $user), 'repository' => null, ...$form->handle($project)]);
    }
}
