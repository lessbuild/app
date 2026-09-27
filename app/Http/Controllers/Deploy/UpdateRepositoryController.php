<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\SaveRepository;
use App\Http\Requests\Deploy\RepositoryRequest;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class UpdateRepositoryController
{
    public function __invoke(RepositoryRequest $request, #[CurrentUser] User $user, Project $project, Repository $repository, SaveRepository $save): RedirectResponse
    {
        $save->handle($user, $project, $request->repository(), $repository);

        return to_route('deploy.repositories.show', [$project, $repository->id, 'tab' => 'settings'])->with('status', __('Repository saved. The next deploy uses these settings.'));
    }
}
