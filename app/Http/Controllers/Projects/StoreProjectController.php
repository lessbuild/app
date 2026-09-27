<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\CreateProject;
use App\Http\Requests\Projects\ProjectRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class StoreProjectController
{
    public function __invoke(ProjectRequest $request, #[CurrentUser] User $user, CreateProject $create): RedirectResponse
    {
        $project = $create->handle($user, $user->currentAccount ?? abort(404), $request->toDetails());

        return to_route('projects.show', $project)->with('status', __('Project created. Next, turn on the services you need.'));
    }
}
