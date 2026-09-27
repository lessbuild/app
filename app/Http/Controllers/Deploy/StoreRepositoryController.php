<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\SaveRepository;
use App\Http\Requests\Deploy\RepositoryRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class StoreRepositoryController
{
    /**
     * Connects a repository to the project.
     */
    public function __invoke(RepositoryRequest $request, #[CurrentUser] User $user, Project $project, SaveRepository $save): RedirectResponse
    {
        $repository = $save->handle($user, $project, $request->repository());

        return to_route('deploy.repositories.show', [$project, $repository->id])->with('status', __('Repository connected. Deploy it when you’re ready.'));
    }
}
