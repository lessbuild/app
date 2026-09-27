<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\SaveStatusPage;
use App\Http\Requests\Monitoring\StatusPageRequest;
use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\StatusPagesQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class UpdateStatusPageController
{
    public function __invoke(StatusPageRequest $request, #[CurrentUser] User $user, Project $project, string $page, StatusPagesQuery $pages, SaveStatusPage $save): RedirectResponse
    {
        $statusPage = $save->handle($project->account, $user, $request->validated(), $pages->find($project->account_id, $page));

        return to_route('monitoring.status-pages.show', [$project, $statusPage->id])->with('status', __('Status page saved.'));
    }
}
