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
        $response->headers->set(
            'Referrer-Policy',
            $request->is('__platform/sso/exchange')
                || is_string($request->attributes->get('platform.sso.form_origin'))
                || $response->headers->get('Referrer-Policy') === 'no-referrer'
                ? 'no-referrer'
                : 'strict-origin-when-cross-origin',
        );
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $platformSsoOrigin = $request->attributes->get('platform.sso.form_origin');
        $formAction = is_string($platformSsoOrigin)
            && preg_match('/\Ahttps?:\/\/[a-z0-9.-]+(?::[0-9]+)?\z/i', $platformSsoOrigin)
            ? "form-action 'self' {$platformSsoOrigin}"
            : "form-action 'self'";

        $response->headers->set('Content-Security-Policy', implode('; ', [
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
            'upgrade-insecure-requests',
        ]));

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
