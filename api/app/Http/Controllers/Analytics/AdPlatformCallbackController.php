<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Models\AnalyticsSite;
use App\Services\Analytics\AdPlatforms;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class AdPlatformCallbackController
{
    /**
     * Take the platform's answer: swap the code for a credential and list its ad accounts, then return to the site's
     * campaigns to choose one. The credential waits in the (encrypted) session until then.
     *
     * @param  Request  $request
     * @param  string  $platform
     * @param  AdPlatforms  $platforms
     * @return RedirectResponse
     */
    public function __invoke(Request $request, string $platform, AdPlatforms $platforms): RedirectResponse
    {
        $pending = $request->session()->pull('ads.connect');
        abort_unless(is_array($pending) && ($pending['platform'] ?? null) === $platform && is_string($request->query('state'))
            && hash_equals((string) ($pending['state'] ?? ''), $request->query('state')), 403);
        $site = AnalyticsSite::query()->where('project_id', (string) ($pending['project'] ?? ''))->whereKey((int) ($pending['site'] ?? 0))->firstOrFail();
        // The app's page shows what happened from ?notice= or ?error=: it can't read this session's flash.
        $back = fn (array $query): RedirectResponse => to_route('analytics.campaigns', [$site->project_id, 'site' => $site->id, ...$query]);
        $code = $request->query('code');
        if (! is_string($code) || $code === '') {
            return $back(['error' => __('The ad account wasn’t connected: access was not allowed.')]);
        }
        try {
            $client = $platforms->for($platform);
            $credential = $client->exchange($code);
            $accounts = $client->accounts($credential);
        } catch (RuntimeException $exception) {
            return $back(['error' => $exception->getMessage()]);
        }
        if ($accounts === []) {
            return $back(['error' => __('That login can’t read any ad accounts.')]);
        }
        $request->session()->put('ads.choose', ['platform' => $platform, 'site' => $site->id, 'credential' => encrypt($credential), 'accounts' => $accounts]);

        return $back(['notice' => __('Choose the ad account to read spend from.')]);
    }
}
