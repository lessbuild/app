<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\ConnectSearchConsole;
use App\Models\AnalyticsSite;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class SearchConsoleCallbackController
{
    /**
     * Finish connecting Search Console when Google sends the person back: check the request came from this session,
     * then store the connection on the site.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  ConnectSearchConsole  $connect
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, ConnectSearchConsole $connect): RedirectResponse
    {
        $pending = $request->session()->pull('search-console.connect');
        abort_unless(is_array($pending) && is_string($request->query('state')) && hash_equals((string) ($pending['state'] ?? ''), $request->query('state')), 403);
        $site = AnalyticsSite::query()->where('project_id', (string) ($pending['project'] ?? ''))->whereKey((int) ($pending['site'] ?? 0))->firstOrFail();
        // The app's page shows what happened from ?notice= or ?error=: it can't read this session's flash.
        $back = fn (array $query): RedirectResponse => to_route('analytics.sites.show', [$site->project_id, $site->id, ...$query]);
        $code = $request->query('code');
        if (! is_string($code) || $code === '') {
            return $back(['error' => __('Search Console wasn’t connected: Google access was not allowed.')]);
        }
        try {
            $connect->handle($user, $site, $code);
        } catch (RuntimeException $exception) {
            return $back(['error' => $exception->getMessage()]);
        }

        return $back(['notice' => __('Search Console is connected.')]);
    }
}
