<?php

namespace App\Core\Http\Controllers\Auth;

use App\Core\Models\PlatformUser;
use App\Core\Services\Auth\PlatformRedirectTarget;
use App\Core\Services\Auth\PlatformSsoHandoff;
use App\Core\Services\Auth\PlatformSsoTickets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class PlatformSsoController
{
    public function issue(Request $request, PlatformSsoHandoff $handoff): Response
    {
        $user = Auth::guard('platform')->user();
        abort_unless($user instanceof PlatformUser && $user->status === 'active', 401);

        $target = $request->query('return_to');
        abort_unless(is_string($target) && strlen($target) <= 2048, 400);

        return $handoff->respond($request, $user, $target);
    }

    public function exchange(
        Request $request,
        PlatformSsoTickets $tickets,
        PlatformRedirectTarget $redirects,
    ): RedirectResponse {
        $data = $request->validate([
            'code' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
        ]);
        $issuerOrigin = $this->issuerOrigin($request);
        $audienceOrigin = $this->normalizeOrigin($request->getSchemeAndHttpHost());
        $exchange = $tickets->consume($data['code'], $issuerOrigin, $audienceOrigin);
        abort_unless($exchange !== null, 403);

        $returnUrl = $redirects->resolve($exchange->returnUrl, $request);
        abort_unless($returnUrl !== null && $this->targetOrigin($returnUrl, $audienceOrigin) === $audienceOrigin, 403);

        Auth::guard('platform')->login($exchange->user, $exchange->authSession->remembered);
        $request->session()->regenerate();
        $request->session()->put('platform.auth.session_id', $exchange->authSession->getKey());

        return redirect()->to($returnUrl)->withHeaders([
            'Cache-Control' => 'private, no-store, max-age=0',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    private function targetOrigin(string $target, string $fallback): string
    {
        $parts = parse_url($target);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return $fallback;
        }

        return $this->normalizeOrigin($parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : ''));
    }

    private function issuerOrigin(Request $request): string
    {
        $origin = trim((string) $request->header('Origin', ''));
        if ($origin !== '' && strtolower($origin) !== 'null') {
            return $this->normalizeOrigin($origin);
        }

        $referer = (string) $request->header('Referer', '');
        $parts = parse_url($referer);
        abort_unless(is_array($parts) && isset($parts['scheme'], $parts['host']), 403);

        return $this->normalizeOrigin(
            $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : ''),
        );
    }

    private function normalizeOrigin(string $origin): string
    {
        $parts = parse_url($origin);
        abort_unless(is_array($parts) && isset($parts['scheme'], $parts['host']), 403);

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : null;

        if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            $port = null;
        }

        return $scheme.'://'.$host.($port === null ? '' : ':'.$port);
    }
}
