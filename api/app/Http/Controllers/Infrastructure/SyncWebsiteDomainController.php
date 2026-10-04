<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\SyncWebsiteDomain;
use App\Models\Project;
use App\Models\Website;
use Illuminate\Http\JsonResponse;

final class SyncWebsiteDomainController
{
    /**
     * Point a managed domain's DNS record at the website's server again.
     *
     * @param  Project  $project
     * @param  Website  $website
     * @param  string  $domain
     * @param  SyncWebsiteDomain  $sync
     * @return JsonResponse
     */
    public function __invoke(Project $project, Website $website, string $domain, SyncWebsiteDomain $sync): JsonResponse
    {
        $warning = $sync->handle($website->domains()->whereNotNull('dns_provider_id')->findOrFail((int) $domain));

        return response()->json([
            'redirect' => route('infrastructure.websites.show', [$project, $website->id, 'tab' => 'domains'], false),
            ...($warning === null ? ['message' => __('DNS record updated.')] : ['warning' => $warning]),
        ]);
    }
}
