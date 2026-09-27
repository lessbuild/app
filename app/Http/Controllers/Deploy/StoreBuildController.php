<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\DeployRepository;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class StoreBuildController
{
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Repository $repository, DeployRepository $deploy): RedirectResponse
    {
        $request->validate(['revision' => ['nullable', 'string', 'regex:/\A[0-9a-fA-F]{40,64}\z/']]);
        $build = $deploy->handle($user, $repository, $request->filled('revision') ? $request->string('revision')->toString() : null);

        return to_route('deploy.builds.show', [$project, $build->id]);
    }
}
