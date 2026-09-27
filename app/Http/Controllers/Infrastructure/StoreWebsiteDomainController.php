<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\SaveWebsiteDomain;
use App\Http\Requests\Infrastructure\WebsiteDomainRequest;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class StoreWebsiteDomainController
{
    public function __invoke(WebsiteDomainRequest $request, #[CurrentUser] User $user, Project $project, Website $website, SaveWebsiteDomain $save): RedirectResponse
    {
        [$domain, $warning] = $save->handle($project->account, $user, $website, $request->domain());

        return to_route('infrastructure.websites.show', [$project, $website->id, 'tab' => 'domains'])->with($warning === null ? 'status' : 'notice', $warning ?? __(':domain added.', ['domain' => $domain->hostname]));
    }
}
