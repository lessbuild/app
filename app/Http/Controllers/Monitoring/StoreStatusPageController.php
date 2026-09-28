<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\SaveStatusPage;
use App\Http\Requests\Monitoring\StatusPageRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class StoreStatusPageController
{
    /**
     * Create a status page.
     *
     * @param  StatusPageRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  SaveStatusPage  $save
     * @return RedirectResponse
     */
    public function __invoke(StatusPageRequest $request, #[CurrentUser] User $user, Project $project, SaveStatusPage $save): RedirectResponse
    {
        $page = $save->handle($project->account, $user, $request->validated());

        return to_route('monitoring.status-pages.show', [$project, $page->id])->with('status', __('Status page saved.'));
    }
}
