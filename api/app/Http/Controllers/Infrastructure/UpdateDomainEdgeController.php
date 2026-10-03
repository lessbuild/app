<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\SaveDomainEdgeSettings;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class UpdateDomainEdgeController
{
    /**
     * Save a domain's CDN and firewall settings and return to the website's Domains tab.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Website  $website
     * @param  string  $domain
     * @param  SaveDomainEdgeSettings  $save
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Website $website, string $domain, SaveDomainEdgeSettings $save): RedirectResponse
    {
        $save->handle($user, $website->domains()->findOrFail((int) $domain), [
            'cdn_proxied' => $request->boolean('cdn_proxied'),
            'blocked_countries' => $request->input('blocked_countries'),
            'blocked_ips' => $request->input('blocked_ips'),
            'rate_limit_requests' => $request->filled('rate_limit_requests') ? $request->input('rate_limit_requests') : null,
        ]);

        return to_route('infrastructure.websites.show', [$project, $website->id, 'tab' => 'domains'])->with('status', __('Saved. Cloudflare is being updated.'));
    }
}
