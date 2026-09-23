<?php

namespace App\Core\Services\Auth;

use App\Core\Models\PlatformUser;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PlatformSsoHandoff
{
    public function __construct(
        private readonly PlatformRedirectTarget $redirects,
        private readonly PlatformSsoTickets $tickets,
    ) {}

    public function respond(Request $request, PlatformUser $user, ?string $target): Response
    {
        $target = $this->redirects->resolve($target, $request);

        if ($target === null) {
            return redirect()->route('core.home');
        }

        $issuerOrigin = $this->requestOrigin($request);
        $audienceOrigin = $this->originFor($target, $issuerOrigin);

        if ($audienceOrigin === $issuerOrigin) {
            return redirect()->to($target);
        }

        $authSessionId = $request->session()->get('platform.auth.session_id');
        abort_unless(is_string($authSessionId), 401);

        $ticket = $this->tickets->issue($user, $authSessionId, $issuerOrigin, $audienceOrigin, $target);
        $request->attributes->set('platform.sso.form_origin', $audienceOrigin);

        return response()->view('core::auth.sso-handoff', [
            'exchangeUrl' => $audienceOrigin.'/__platform/sso/exchange',
            'ticket' => $ticket,
            'destination' => parse_url($audienceOrigin, PHP_URL_HOST) ?: __('your application'),
        ])->withHeaders([
            'Cache-Control' => 'private, no-store, max-age=0',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
        ]);
    }

    private function requestOrigin(Request $request): string
    {
        return $this->normalizeOrigin($request->getSchemeAndHttpHost());
    }

    private function originFor(string $target, string $fallback): string
    {
        $parts = parse_url($target);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return $fallback;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $this->normalizeOrigin($scheme.'://'.$host.$port);
    }

    private function normalizeOrigin(string $origin): string
    {
        $parts = parse_url($origin);
        abort_unless(is_array($parts) && isset($parts['scheme'], $parts['host']), 400);

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : null;

        if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            $port = null;
        }

        return $scheme.'://'.$host.($port === null ? '' : ':'.$port);
    }
}
