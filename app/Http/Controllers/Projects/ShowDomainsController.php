<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectDomainsQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowDomainsController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ProjectDomainsQuery $domains): View
    {
        return view('projects.domains', [
            'overview' => $overview->handle($project, $user),
            'domains' => $domains->handle($project),
        ]);
    }
}
