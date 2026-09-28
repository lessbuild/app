<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\CreateProject;
use App\Http\Attributes\CurrentAccount;
use App\Http\Requests\Projects\ProjectRequest;
use App\Models\Account;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class StoreProjectController
{
    /**
     * Create a project and suggests turning services on.
     *
     * @param  Account  $account
     * @param  ProjectRequest  $request
     * @param  User  $user
     * @param  CreateProject  $create
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, ProjectRequest $request, #[CurrentUser] User $user, CreateProject $create): RedirectResponse
    {
        $project = $create->handle($user, $account, $request->toDetails());

        return to_route('projects.show', $project)->with('status', __('Project created. Next, turn on the services you need.'));
    }
}
