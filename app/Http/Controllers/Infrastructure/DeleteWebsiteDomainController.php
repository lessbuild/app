<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\DeleteWebsiteDomain;
use App\Models\Project;
use App\Models\User;
use App\Queries\Infrastructure\WebsitesQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DeleteWebsiteDomainController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, string $website, string $domain, WebsitesQuery $websites, DeleteWebsiteDomain $delete): RedirectResponse
    {
        $record = $websites->find($project->account_id, $website);
        $delete->handle($project->account, $user, $record->domains()->findOrFail((int) $domain));

        return to_route('infrastructure.websites.show', [$project, $record->id])->with('status', __('Domain removed.'));
    }
}
