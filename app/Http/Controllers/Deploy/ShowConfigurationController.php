<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Models\Project;
use App\Models\User;
use App\Queries\Deploy\ConfigurationQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowConfigurationController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ConfigurationQuery $configuration): View
    {
        return view('deploy.configuration', ['overview' => $overview->handle($project, $user), 'plan' => session('plan'), ...$configuration->page($project)]);
    }
}
