<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\UpdateRepositoryPreviews;
use App\Http\Requests\Deploy\PreviewSettingsRequest;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

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
     * @return RedirectResponse
     */
    public function __invoke(PreviewSettingsRequest $request, #[CurrentUser] User $user, Project $project, Repository $repository, UpdateRepositoryPreviews $update): RedirectResponse
    {
        $update->handle($user, $repository, $request->settings());

        return to_route('deploy.repositories.show', [$project, $repository->id, 'tab' => 'settings'])->with('status', __('Preview settings saved.'));
    }
}
