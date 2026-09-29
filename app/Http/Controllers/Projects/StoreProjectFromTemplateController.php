<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\CreateProjectFromTemplate;
use App\Http\Attributes\CurrentAccount;
use App\Http\Requests\Projects\ProjectTemplateRequest;
use App\Models\Account;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class StoreProjectFromTemplateController
{
    /**
     * Set up a project from a template and open its setup guide, where the website's progress and first deploy show.
     *
     * @param  ProjectTemplateRequest  $request
     * @param  Account  $account
     * @param  User  $user
     * @param  CreateProjectFromTemplate  $create
     * @return RedirectResponse
     */
    public function __invoke(ProjectTemplateRequest $request, #[CurrentAccount] Account $account, #[CurrentUser] User $user, CreateProjectFromTemplate $create): RedirectResponse
    {
        $project = $create->handle($user, $account, $request->string('template')->toString(), $request->details());

        return to_route('projects.setup', $project)->with('status', __(':name is being set up: the website is provisioning, and pushes to :branch deploy once it’s ready.', ['name' => $project->name, 'branch' => $request->string('branch')->toString()]));
    }
}
