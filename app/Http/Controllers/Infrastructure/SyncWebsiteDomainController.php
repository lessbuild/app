<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\SyncWebsiteDomain;
use App\Models\Project;
use App\Models\Website;
use Illuminate\Http\RedirectResponse;

final class SyncWebsiteDomainController
{
    public function __invoke(Project $project, Website $website, string $domain, SyncWebsiteDomain $sync): RedirectResponse
    {
        $warning = $sync->handle($website->domains()->whereNotNull('dns_provider_id')->findOrFail((int) $domain));

        return to_route('infrastructure.websites.show', [$project, $website->id])->with($warning === null ? 'status' : 'notice', $warning ?? __('DNS record updated.'));
    }
}
