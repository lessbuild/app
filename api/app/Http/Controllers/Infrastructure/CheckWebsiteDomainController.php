<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\CheckWebsiteDomain;
use App\Models\Project;
use App\Models\Website;
use Illuminate\Http\JsonResponse;

/** `POST /api/app/projects/{project}/infrastructure/websites/{website}/domains/{domain}/check`. */
final class CheckWebsiteDomainController
{
    /**
     * Check a domain's DNS and certificate now, rather than waiting for the hourly check.
     *
     * @param  Project  $project
     * @param  Website  $website
     * @param  string  $domain
     * @param  CheckWebsiteDomain  $check
     * @return JsonResponse
     */
    public function __invoke(Project $project, Website $website, string $domain, CheckWebsiteDomain $check): JsonResponse
    {
        $checked = $check->handle($website->domains()->findOrFail((int) $domain));

        return response()->json(['message' => $checked->last_error === null
            ? __(':domain points here and its certificate is valid.', ['domain' => $checked->hostname])
            : $checked->last_error]);
    }
}
