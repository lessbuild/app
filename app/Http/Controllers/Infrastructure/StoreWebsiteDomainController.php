<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\SaveWebsiteDomain;
use App\Http\Requests\Infrastructure\WebsiteDomainRequest;
use App\Models\Project;
use App\Models\User;
use App\Queries\Infrastructure\WebsitesQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class StoreWebsiteDomainController
{
    public function __invoke(WebsiteDomainRequest $request, #[CurrentUser] User $user, Project $project, string $website, WebsitesQuery $websites, SaveWebsiteDomain $save): RedirectResponse
    {
        [$domain, $warning] = $save->handle($project->account, $user, $websites->find($project->account_id, $website), $request->domain());

        return to_route('infrastructure.websites.show', [$project, (int) $website])->with($warning === null ? 'status' : 'notice', $warning ?? __(':domain added.', ['domain' => $domain->hostname]));
    }
}
