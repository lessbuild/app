<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\SetReleaseNotesPage;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class UpdateReleaseNotesPageController
{
    /**
     * Publish (PUT) or take down (DELETE) the environment's public release notes and return to it.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Environment  $environment
     * @param  SetReleaseNotesPage  $set
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Environment $environment, SetReleaseNotesPage $set): RedirectResponse
    {
        abort_unless($environment->project_id === $project->id, 404);
        $set->handle($user, $environment, $request->isMethod('PUT'));

        return to_route('deploy.environments.show', [$project, $environment, 'tab' => 'controls'])->with('status', $request->isMethod('PUT') ? __('Release notes are public.') : __('Release notes are private again.'));
    }
}
