<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\ConnectGoogleAnalytics;
use App\Models\AnalyticsSite;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class GoogleAnalyticsCallbackController
{
    /**
     * Finish connecting Google Analytics for the site the person started from, then return to its settings to choose
     * what to import. A mismatched state is refused.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  ConnectGoogleAnalytics  $connect
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, ConnectGoogleAnalytics $connect): RedirectResponse
    {
        $pending = $request->session()->pull('google-analytics.connect');
        abort_unless(is_array($pending) && is_string($request->query('state')) && hash_equals((string) ($pending['state'] ?? ''), $request->query('state')), 403);
        $site = AnalyticsSite::query()->where('project_id', (string) ($pending['project'] ?? ''))->whereKey((int) ($pending['site'] ?? 0))->firstOrFail();
        $back = to_route('analytics.sites.show', [$site->project_id, $site->id]);
        $code = $request->query('code');
        if (! is_string($code) || $code === '') {
            return $back->withErrors(['property' => __('Google Analytics wasn’t connected: Google access was not allowed.')]);
        }
        try {
            $connect->handle($user, $site, $code);
        } catch (RuntimeException $exception) {
            return $back->withErrors(['property' => $exception->getMessage()]);
        }

        return $back->with('status', __('Google Analytics is connected. Choose what to import.'));
    }
}
