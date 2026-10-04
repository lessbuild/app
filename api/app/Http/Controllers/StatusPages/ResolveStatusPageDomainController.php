<?php

declare(strict_types=1);

namespace App\Http\Controllers\StatusPages;

use App\Models\StatusPage;
use Illuminate\Http\JsonResponse;

final class ResolveStatusPageDomainController
{
    /**
     * Say which status page a custom domain shows, so the app can serve it at the domain's root. Only verified domains
     * of published pages answer; anything else is a 404.
     *
     * @param  string  $host
     * @return JsonResponse
     */
    public function __invoke(string $host): JsonResponse
    {
        $page = StatusPage::query()->where('custom_domain', strtolower($host))->whereNotNull('custom_domain_verified_at')->where('published', true)->firstOrFail(['slug']);

        return response()->json(['slug' => $page->slug]);
    }
}
