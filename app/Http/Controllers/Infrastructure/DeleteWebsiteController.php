<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\DeleteWebsite;
use App\Models\Project;
use App\Models\User;
use App\Queries\Infrastructure\WebsitesQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DeleteWebsiteController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, string $website, WebsitesQuery $websites, DeleteWebsite $delete): RedirectResponse
    {
        $delete->handle($project->account, $user, $websites->find($project->account_id, $website));

        return to_route('infrastructure.websites', $project)->with('status', __('Website deleted. It’s being removed from its server.'));
    }
}
