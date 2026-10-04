<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\SaveWebsiteDomain;
use App\Http\Requests\Infrastructure\WebsiteDomainRequest;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class StoreWebsiteDomainController
{
    /**
     * Add a domain to a website, with a notice when its DNS couldn't be set up.
     *
     * @param  WebsiteDomainRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Website  $website
     * @param  SaveWebsiteDomain  $save
     * @return JsonResponse
     */
    public function __invoke(WebsiteDomainRequest $request, #[CurrentUser] User $user, Project $project, Website $website, SaveWebsiteDomain $save): JsonResponse
    {
        [$domain, $warning] = $save->handle($project->account, $user, $website, $request->domain());

        return response()->json([
            'redirect' => route('infrastructure.websites.show', [$project, $website->id, 'tab' => 'domains'], false),
            ...($warning === null ? ['message' => __(':domain added.', ['domain' => $domain->hostname])] : ['warning' => $warning]),
        ]);
    }
}
