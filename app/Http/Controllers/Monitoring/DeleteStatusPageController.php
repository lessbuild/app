<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\DeleteStatusPage;
use App\Models\Project;
use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DeleteStatusPageController
{
    /**
     * Deletes a status page.
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, StatusPage $page, DeleteStatusPage $delete): RedirectResponse
    {
        $delete->handle($project->account, $user, $page);

        return to_route('monitoring.status-pages', $project)->with('status', __('Status page deleted.'));
    }
}
