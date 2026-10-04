<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Identity\AccountSso;
use App\Support\IpRange;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the current account's security rules to every signed-in request: its allowed IP ranges, signing people out
 * after inactivity, requiring single sign-on, and requiring a second factor. Signing out, switching account, your own
 * settings and the sign-on flow itself stay reachable, so nobody is trapped.
 */
final class EnforceAccountSecurity
{
    /**
     * Routes that always stay reachable, so someone blocked by a rule can fix it, switch account or sign out.
     *
     * @var list<string>
     */
    private const ALWAYS_ALLOWED = [
        'logout', 'accounts.switch', 'sso.*', 'settings.*', 'two-factor.*', 'password.confirm*', 'passkey.*', 'user-password.*', 'user-profile-information.*',
        // The same, as the app's API names them (routes/app.php, under app.).
        'app.accounts.switch', 'app.settings.*', 'app.auth.*',
    ];

    /**
     * Create a new EnforceAccountSecurity instance.
     *
     * @param  AccountSso  $sso  Knows whether the session has signed in through the account's provider.
     */
    public function __construct(private readonly AccountSso $sso) {}

    /**
     * Apply the current account's rules, or let the request through when it has none.
     *
     * @param  Request  $request
     * @param  Closure(Request): Response  $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $account = $user instanceof User ? $user->currentAccount : null;
        if ($account === null || $request->routeIs(...self::ALWAYS_ALLOWED)) {
            return $next($request);
        }

        $ranges = $account->allowed_ip_ranges ?? [];
        if ($ranges !== [] && ! IpRange::any($ranges, (string) $request->ip())) {
            abort(403, __(':account can only be used from approved networks. Switch account or connect from an approved network.', ['account' => $account->name]));
        }

        $key = 'account.activity.'.$account->id;
        $last = $request->session()->get($key);
        if ($account->session_idle_minutes !== null && is_int($last) && now()->getTimestamp() - $last > $account->session_idle_minutes * 60) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', __('You were signed out after :minutes minutes without activity.', ['minutes' => $account->session_idle_minutes]));
        }
        $request->session()->put($key, now()->getTimestamp());

        if ($account->sso_enforced && $account->hasSso() && ! $this->sso->verified($request->session(), $account)) {
            return redirect()->guest(route('sso.verify'));
        }

        if ($account->require_two_factor && ! $user->hasSecondFactor()) {
            return redirect()->route('settings.security')->with('warning', __(':account requires two-factor authentication or a passkey. Turn one on to continue.', ['account' => $account->name]));
        }

        return $next($request);
    }
}
