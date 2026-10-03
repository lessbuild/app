<?php

declare(strict_types=1);

namespace App\Http\Controllers\StatusPages;

use App\Models\StatusPage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Caddy's on-demand TLS check: it asks before getting a certificate for a hostname it hasn't seen. */
final class CheckStatusPageDomainController
{
    /**
     * Answer 200 when `?domain=` is a published status page's verified custom domain, and 404 otherwise, so
     * certificates are only issued for domains customers have proved they control.
     *
     * @param  Request  $request
     * @return Response
     */
    public function __invoke(Request $request): Response
    {
        $domain = strtolower(trim((string) $request->query('domain')));
        $allowed = $domain !== '' && StatusPage::query()->where('custom_domain', $domain)->whereNotNull('custom_domain_verified_at')->where('published', true)->exists();

        return response('', $allowed ? 200 : 404);
    }
}
