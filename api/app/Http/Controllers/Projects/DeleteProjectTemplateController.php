<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\DeleteProjectTemplate;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\ProjectTemplate;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class DeleteProjectTemplateController
{
    /**
     * Remove a saved template.
     *
     * @param  Account  $account
     * @param  User  $user
     * @param  ProjectTemplate  $projectTemplate
     * @param  DeleteProjectTemplate  $delete
     * @return JsonResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, #[CurrentUser] User $user, ProjectTemplate $projectTemplate, DeleteProjectTemplate $delete): JsonResponse
    {
        $delete->handle($user, $account, $projectTemplate);

        return response()->json(['redirect' => route('projects.templates', [], false), 'message' => __('Template removed.')]);
    }
}
