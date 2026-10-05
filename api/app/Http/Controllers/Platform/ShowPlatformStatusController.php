<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Services\Admin\PlatformStatus;
use App\Services\Admin\PlatformStatusHistory;
use Illuminate\Http\JsonResponse;

final class ShowPlatformStatusController
{
    /**
     * Show BuildPusher's own status: each part and whether it's working now, with its last 90 days and uptime, and the
     * recent incidents. When a status page is set as the platform's (config platform.status_page), the app goes there
     * instead.
     *
     * @param  PlatformStatus  $status
     * @param  PlatformStatusHistory  $history
     * @return JsonResponse
     */
    public function __invoke(PlatformStatus $status, PlatformStatusHistory $history): JsonResponse
    {
        $page = config('platform.status_page');
        if (is_string($page) && $page !== '') {
            return response()->json(['redirect' => route('status.show', $page, false)]);
        }
        $snapshot = $status->snapshot();
        $days = $history->days(array_map(fn (array $component): string => $component['key'], $snapshot['components']));

        return response()->json([
            'operational' => $snapshot['operational'],
            'checkedAt' => $snapshot['checked_at'],
            'components' => array_map(fn (array $component): array => [...$component, ...($days[$component['key']] ?? ['days' => [], 'uptime' => null])], $snapshot['components']),
            'historyDays' => PlatformStatusHistory::DAYS,
            'incidents' => $history->incidents(),
            'reportUrl' => route('platform.status.report'),
        ]);
    }
}
