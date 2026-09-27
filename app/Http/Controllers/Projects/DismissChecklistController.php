<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\DismissChecklist;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DismissChecklistController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, DismissChecklist $dismiss): RedirectResponse
    {
        $dismiss->handle($user, $project);

        return to_route('projects.show', $project);
    }
}
