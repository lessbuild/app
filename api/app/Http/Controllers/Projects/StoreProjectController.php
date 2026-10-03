<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\CreateProject;
use App\Actions\Projects\SyncProjectServices;
use App\Http\Attributes\CurrentAccount;
use App\Http\Requests\Projects\ProjectRequest;
use App\Models\Account;
use App\Models\User;
use App\Platform\ServiceRegistry;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

/** `POST /api/app/projects`. */
final class StoreProjectController
{
    /**
     * Create a project with the services the person chose, then go to its setup guide.
     *
     * @param  Account  $account
     * @param  ProjectRequest  $request
     * @param  User  $user
     * @param  CreateProject  $create
     * @param  SyncProjectServices  $services
     * @param  ServiceRegistry  $registry
     * @return JsonResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, ProjectRequest $request, #[CurrentUser] User $user, CreateProject $create, SyncProjectServices $services, ServiceRegistry $registry): JsonResponse
    {
        $chosen = $request->validate(['services' => ['sometimes', 'array'], 'services.*' => ['string', Rule::in($registry->keys())]])['services'] ?? [];
        $project = $create->handle($user, $account, $request->toDetails());
        $services->handle($user, $project, array_values(array_unique($chosen)));

        return response()->json([
            'id' => $project->id,
            'redirect' => route('projects.setup', $project, false),
            'message' => __('Project created. Here’s what to set up next.'),
        ], 201);
    }
}
