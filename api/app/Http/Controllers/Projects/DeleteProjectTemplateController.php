<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\DeleteProjectTemplate;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\ProjectTemplate;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DeleteProjectTemplateController
{
    /**
     * Remove a saved template.
     *
     * @param  Account  $account
     * @param  User  $user
     * @param  ProjectTemplate  $projectTemplate
     * @param  DeleteProjectTemplate  $delete
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, #[CurrentUser] User $user, ProjectTemplate $projectTemplate, DeleteProjectTemplate $delete): RedirectResponse
    {
        $delete->handle($user, $account, $projectTemplate);

        return to_route('projects.templates')->with('status', __('Template removed.'));
    }
}
