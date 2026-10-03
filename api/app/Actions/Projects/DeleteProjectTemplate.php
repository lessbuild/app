<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Models\Account;
use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class DeleteProjectTemplate
{
    /**
     * Remove a saved template. Projects made from it are unaffected.
     *
     * @param  User  $actor
     * @param  Account  $account
     * @param  ProjectTemplate  $template
     * @return void
     */
    public function handle(User $actor, Account $account, ProjectTemplate $template): void
    {
        abort_if($template->account_id !== $account->id, 404);
        Gate::forUser($actor)->authorize('create', [Project::class, $account]);
        $template->delete();
    }
}
