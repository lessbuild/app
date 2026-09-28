<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\SaveStatusPage;
use App\Http\Requests\Monitoring\StatusPageRequest;
use App\Models\Project;
use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class UpdateStatusPageController
{
    /**
     * Save a status page.
     *
     * @param  StatusPageRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  StatusPage  $page
     * @param  SaveStatusPage  $save
     * @return RedirectResponse
     */
    public function __invoke(StatusPageRequest $request, #[CurrentUser] User $user, Project $project, StatusPage $page, SaveStatusPage $save): RedirectResponse
    {
        $statusPage = $save->handle($project->account, $user, $request->validated(), $page);

        return to_route('monitoring.status-pages.show', [$project, $statusPage->id])->with('status', __('Status page saved.'));
    }
}
