<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustHosts as Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrustHosts extends Middleware
{
    /**
     * Get the host patterns that should be trusted.
     *
     * @return array<int, string|null>
     */
    public function hosts(): array
    {
        $hosts = [
            $this->allSubdomainsOfApplicationUrl(),
            '^localhost$',
            '^127\.0\.0\.1$',
            '^::1$',
        ];

        foreach (config('lessbuild.trusted_hosts', []) as $host) {
            $host = strtolower(trim((string) $host));
            if ($host !== '') {
                $hosts[] = '^'.preg_quote($host, '/').'$';
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * Validate the incoming host before forwarding the request to the next middleware.
     *
     * @param  callable(Request): Response  $next  The next handler; the parent contract is untyped.
     * @return Response The downstream response after host validation.
     */
    public function handle(Request $request, mixed $next): Response
    {
        Request::setTrustedHosts(array_filter($this->hosts()));
        $request->getHost();

        return $next($request);
    }
}
