<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceOrganizationSecurity
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $organization = $user?->currentOrganization;
        if (! $user || ! $organization) {
            return $next($request);
        }

        $ranges = array_filter($organization->allowed_ip_ranges ?? []);
        abort_if($ranges !== [] && ! collect($ranges)->contains(fn (string $range): bool => $this->contains($range, (string) $request->ip())), 403, 'This network is not allowed by the workspace security policy.');

        if ($organization->require_two_factor && ! $user->twoFactorEnabled()
            && ! $request->routeIs('account.*', 'logout')) {
            return redirect()->route('account.index')->with('error', __('This workspace requires two-factor authentication. Enable it to continue.'));
        }

        if ($organization->sso_enforced && ! $request->session()->has('organization_sso_verified.'.$organization->id)
            && ! $request->routeIs('organizations.sso.*', 'organizations.index', 'organizations.security-policy.update', 'account.*', 'logout')) {
            return redirect()->route('organizations.sso.connect');
        }

        $minutes = $organization->session_idle_minutes;
        $key = 'organization_last_activity.'.$organization->id;
        $last = (int) $request->session()->get($key, time());
        if ($minutes && time() - $last > $minutes * 60) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', __('Your workspace session expired due to inactivity.'));
        }
        $request->session()->put($key, time());

        return $next($request);
    }

    public function contains(string $range, string $ip): bool
    {
        [$network, $prefix] = array_pad(explode('/', trim($range), 2), 2, null);
        $address = @inet_pton($ip);
        $base = @inet_pton($network);
        if ($address === false || $base === false || strlen($address) !== strlen($base)) {
            return false;
        }
        $bits = $prefix === null ? strlen($address) * 8 : filter_var($prefix, FILTER_VALIDATE_INT);
        if ($bits === false || $bits < 0 || $bits > strlen($address) * 8) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;
        if (substr($address, 0, $bytes) !== substr($base, 0, $bytes)) {
            return false;
        }
        if ($remainder === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($address[$bytes]) & $mask) === (ord($base[$bytes]) & $mask);
    }
}
