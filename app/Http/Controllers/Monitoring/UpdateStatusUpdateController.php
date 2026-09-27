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

final class UpdateStatusUpdateController
{
    public function __invoke(StatusUpdateRequest $request, #[CurrentUser] User $user, Project $project, StatusPage $page, string $update, SaveStatusUpdate $save): RedirectResponse
    {
        $save->handle($page, $user, $request->validated(), $page->updates()->findOrFail((int) $update));

        return to_route('monitoring.status-pages.show', [$project, $page->id])->with('status', __('Update saved.'));
    }
}
