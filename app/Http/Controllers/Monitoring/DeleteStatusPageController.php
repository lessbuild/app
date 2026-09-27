<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\DeleteStatusPage;
use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\StatusPagesQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DeleteStatusPageController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, string $page, StatusPagesQuery $pages, DeleteStatusPage $delete): RedirectResponse
    {
        $delete->handle($project->account, $user, $pages->find($project->account_id, $page));

        return to_route('monitoring.status-pages', $project)->with('status', __('Status page deleted.'));
    }
}
