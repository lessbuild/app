<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\StatusPage;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves status pages on their customers' own domains. On such a host, `/` is the page, `/report.json` its report and
 * `/subscribe` its sign-up form; `/status/…` links (confirmations, unsubscribes) work as on the platform's own host.
 * Nothing else of the platform is reachable there.
 */
final class ServeStatusPageDomains
{
    /**
     * Paths that pass through unchanged on a status page domain, including the API the status page itself calls.
     *
     * @var list<string>
     */
    private const PASS_THROUGH = ['status/*', 'api/app/status/*', 'api/app/status-domains/*', 'sanctum/csrf-cookie', 'up', 'favicon.ico', 'robots.txt', 'build/*'];

    /**
     * Rewrite requests to a verified status page domain onto the page's routes, and refuse everything else there.
     * Requests to the platform's own host, or to hosts no page has verified, go on untouched.
     *
     * @param  Request  $request
     * @param  Closure(Request): Response  $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower($request->getHost());
        if ($host === '' || $host === parse_url((string) config('app.url'), PHP_URL_HOST)) {
            return $next($request);
        }
        $page = StatusPage::query()->where('custom_domain', $host)->whereNotNull('custom_domain_verified_at')->where('published', true)->first(['id', 'slug']);
        if ($page === null) {
            return $next($request);
        }
        if ($request->is(...self::PASS_THROUGH)) {
            return $next($request);
        }
        $path = match ($request->path()) {
            '/' => "/status/{$page->slug}",
            'report.json' => "/status/{$page->slug}/report.json",
            'subscribe' => "/status/{$page->slug}/subscribe",
            'badge.svg' => "/status/{$page->slug}/badge.svg",
            'embed' => "/status/{$page->slug}/embed",
            default => null,
        };
        abort_if($path === null, 404);

        $rewritten = $request->duplicate();
        $query = $request->getQueryString();
        $rewritten->server->set('REQUEST_URI', $path.($query !== null ? '?'.$query : ''));

        return $next($rewritten);
    }
}
