<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\SaveStatusUpdate;
use App\Http\Requests\Monitoring\StatusUpdateRequest;
use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\StatusPagesQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class UpdateStatusUpdateController
{
    public function __invoke(StatusUpdateRequest $request, #[CurrentUser] User $user, Project $project, string $page, string $update, StatusPagesQuery $pages, SaveStatusUpdate $save): RedirectResponse
    {
        $statusPage = $pages->find($project->account_id, $page);
        $save->handle($statusPage, $user, $request->validated(), $statusPage->updates()->findOrFail((int) $update));

        return to_route('monitoring.status-pages.show', [$project, $statusPage->id])->with('status', __('Update saved.'));
    }
}
