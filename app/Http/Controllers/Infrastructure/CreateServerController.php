<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Models\Project;
use App\Models\User;
use App\Queries\Infrastructure\ServerCreateFormQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** Pick a provider, then its region, size and Ubuntu image (read live from the provider). */
final class CreateServerController
{
    /**
     * Show the new server form: inside the Servers page's modal, which asks for just the form (X-Fragment), or as a
     * page of its own when JavaScript isn't running.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  ServerCreateFormQuery  $form
     * @return View
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ServerCreateFormQuery $form): View
    {
        $data = ['project' => $project, ...$form->handle($project, $request->query('provider'))];
        if ($request->hasHeader('X-Fragment')) {
            return view('infrastructure._server-create-form', [...$data, 'inModal' => true]);
        }

        return view('infrastructure.server-create', ['overview' => $overview->handle($project, $user), ...$data, 'inModal' => false]);
    }
}
