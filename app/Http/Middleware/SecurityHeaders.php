<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hardening headers on every web response. The Content Security Policy only runs the app's own scripts and inline
 * scripts carrying this request's nonce (Vite adds it to the bundles), never eval or inline event handlers. Public
 * status pages may be embedded in other sites; nothing else may be framed.
 */
final class SecurityHeaders
{
    /**
     * Give the request a script nonce, then add the headers to the response.
     *
     * @param  Request  $request
     * @param  Closure(Request): Response  $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $embeddable = $request->routeIs('status.show', 'status.embed');
        if (! $embeddable) {
            $headers->set('X-Frame-Options', 'DENY');
        }
        if ($request->isSecure() && app()->isProduction()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        // The Vite dev server injects its own client, so the policy only applies to built assets.
        // Cloudflare Turnstile (on sign-up, when set up) loads a script and a frame from Cloudflare.
        $turnstile = filled(config('services.turnstile.site_key')) ? ' https://challenges.cloudflare.com' : '';
        if (! Vite::isRunningHot() && ! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'nonce-{$nonce}'{$turnstile}",
                "frame-src 'self'{$turnstile}",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data: https:",
                "font-src 'self' data:",
                "connect-src 'self'",
                "object-src 'none'",
                "base-uri 'self'",
                'frame-ancestors '.($embeddable ? '*' : "'none'"),
            ]));
        }

        return $response;
    }
}
