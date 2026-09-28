<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\SaveStatusUpdate;
use App\Http\Requests\Monitoring\StatusUpdateRequest;
use App\Models\Project;
use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class StoreStatusUpdateController
{
    /**
     * Post an update; subscribers are emailed when the page is published.
     *
     * @param  StatusUpdateRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  StatusPage  $page
     * @param  SaveStatusUpdate  $save
     * @return RedirectResponse
     */
    public function __invoke(StatusUpdateRequest $request, #[CurrentUser] User $user, Project $project, StatusPage $page, SaveStatusUpdate $save): RedirectResponse
    {
        $save->handle($page, $user, $request->validated());

        return to_route('monitoring.status-pages.show', [$project, $page->id])->with('status', $page->published
            ? __('Update posted. Subscribers are being emailed.')
            : __('Update saved. It shows once the page is published.'));
    }
}
