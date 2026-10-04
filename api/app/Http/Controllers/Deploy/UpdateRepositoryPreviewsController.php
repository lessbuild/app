<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\UpdateRepositoryPreviews;
use App\Http\Requests\Deploy\PreviewSettingsRequest;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class UpdateRepositoryPreviewsController
{
    /**
     * Save a repository's preview settings and return to its settings tab.
     *
     * @param  PreviewSettingsRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Repository  $repository
     * @param  UpdateRepositoryPreviews  $update
     * @return JsonResponse
     */
    public function __invoke(PreviewSettingsRequest $request, #[CurrentUser] User $user, Project $project, Repository $repository, UpdateRepositoryPreviews $update): JsonResponse
    {
        $update->handle($user, $repository, $request->settings());

        return response()->json(['redirect' => route('deploy.repositories.show', [$project, $repository->id, 'tab' => 'settings'], false), 'message' => __('Preview settings saved.')]);
    }
}
