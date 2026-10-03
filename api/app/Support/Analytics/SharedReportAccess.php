<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use App\Models\AnalyticsSite;
use Illuminate\Http\Request;

/** Remembers, per browser session, that a password-protected shared report was unlocked. */
final class SharedReportAccess
{
    /**
     * Determine whether this browser may see the shared report: always when it has no password, else once the
     * current password was entered in this session.
     *
     * @param  Request  $request
     * @param  AnalyticsSite  $site
     * @return bool
     */
    public static function unlocked(Request $request, AnalyticsSite $site): bool
    {
        return $site->share_password === null
            || hash_equals(self::sessionProof($site), (string) $request->session()->get(self::sessionKey($site), ''));
    }

    /**
     * Get the session key that remembers a shared report was unlocked.
     *
     * @param  AnalyticsSite  $site
     * @return string
     */
    public static function sessionKey(AnalyticsSite $site): string
    {
        return 'analytics-share.'.$site->id;
    }

    /**
     * Get the value stored in the session once unlocked. It changes with the password, so changing it locks everyone
     * out again.
     *
     * @param  AnalyticsSite  $site
     * @return string
     */
    public static function sessionProof(AnalyticsSite $site): string
    {
        return hash_hmac('sha256', (string) $site->share_token.'|'.(string) $site->share_password, (string) config('app.key'));
    }
}
