<?php

namespace App\Modules\Deployer\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AddSecurityHeaders
{
    /**
     * Add browser isolation and content policies to the downstream response, with HSTS only for HTTPS requests.
     *
     * @param  Closure(Request): Response  $next  The remaining HTTP middleware pipeline.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $referrerPolicy = match (true) {
            $request->is('__platform/sso/exchange'),
            $response->headers->get('Referrer-Policy') === 'no-referrer' => 'no-referrer',
            is_string($request->attributes->get('platform.sso.form_origin')) => 'strict-origin',
            default => 'strict-origin-when-cross-origin',
        };
        $response->headers->set('Referrer-Policy', $referrerPolicy);
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $formAction = $this->formActionPolicy($request);

        $contentSecurityPolicy = [
            "default-src 'self'",
            "base-uri 'self'",
            "frame-ancestors 'self'",
            $formAction,
            "img-src 'self' data:",
            "font-src 'self' data:",
            "style-src 'self' 'unsafe-inline'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
            "connect-src 'self'",
            "object-src 'none'",
        ];

        if ($request->isSecure()) {
            $contentSecurityPolicy[] = 'upgrade-insecure-requests';
        }

        $response->headers->set('Content-Security-Policy', implode('; ', $contentSecurityPolicy));

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }

    private function formActionPolicy(Request $request): string
    {
        $origins = ["'self'"];
        $configuredOrigins = [
            $request->getSchemeAndHttpHost(),
            config('app.url'),
            config('platform.dashboard_url'),
            config('platform.auth_url'),
            $request->attributes->get('platform.sso.form_origin'),
        ];

        foreach (config('platform.products', []) as $product) {
            $configuredOrigins[] = $product['url'] ?? null;
        }

        foreach ($configuredOrigins as $configuredOrigin) {
            if (! is_string($configuredOrigin) || trim($configuredOrigin) === '') {
                continue;
            }

            $value = trim($configuredOrigin);
            if (! str_contains($value, '://')) {
                $value = 'https://'.$value;
            }

            $parts = parse_url($value);
            if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])
                || isset($parts['user']) || isset($parts['pass'])
                || ! preg_match('/\A[a-z0-9.-]+\z/i', (string) $parts['host'])) {
                continue;
            }

            $scheme = strtolower((string) $parts['scheme']);
            if (! in_array($scheme, ['http', 'https'], true)
                || ($scheme === 'http' && (! in_array(app()->environment(), ['local', 'testing'], true) || $request->isSecure()))) {
                continue;
            }

            $port = isset($parts['port']) ? (int) $parts['port'] : null;
            if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
                $port = null;
            }

            $origins[] = $scheme.'://'.strtolower((string) $parts['host']).($port === null ? '' : ':'.$port);
        }

        return 'form-action '.implode(' ', array_unique($origins));
    }
}
