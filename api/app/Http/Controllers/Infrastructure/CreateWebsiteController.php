<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Models\Project;
use App\Models\User;
use App\Queries\Infrastructure\WebsitesQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class CreateWebsiteController
{
    /**
     * Show the new website form, or the import form with `?import=1`.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  WebsitesQuery  $websites
     * @return View
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, WebsitesQuery $websites): View
    {

        return view('infrastructure.website-form', [
            'overview' => $overview->handle($project, $user),
            'website' => null,
            'hosts' => $websites->hosts($project->account_id),
            'environments' => $websites->environments($project->account),
            'importing' => $request->query('import') === '1',
        ]);
    }
}
