<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\SyncWebsiteDomain;
use App\Models\Project;
use App\Queries\Infrastructure\WebsitesQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

final class SyncWebsiteDomainController
{
    public function __invoke(Project $project, string $website, string $domain, WebsitesQuery $websites, SyncWebsiteDomain $sync): RedirectResponse
    {
        Gate::authorize('update', $project->account);
        $record = $websites->find($project->account_id, $website);
        $warning = $sync->handle($record->domains()->whereNotNull('dns_provider_id')->findOrFail((int) $domain));

        return to_route('infrastructure.websites.show', [$project, $record->id])->with($warning === null ? 'status' : 'notice', $warning ?? __('DNS record updated.'));
    }
}
