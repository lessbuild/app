<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Services\Admin\PlatformStatus;
use Illuminate\Http\JsonResponse;

final class ShowPlatformStatusController
{
    /**
     * Show BuildPusher's own status: its services and whether each is working. When a status page is set as the
     * platform's (config platform.status_page), the app goes there instead.
     *
     * @param  PlatformStatus  $status
     * @return JsonResponse
     */
    public function __invoke(PlatformStatus $status): JsonResponse
    {
        $page = config('platform.status_page');
        if (is_string($page) && $page !== '') {
            return response()->json(['redirect' => route('status.show', $page, false)]);
        }
        $snapshot = $status->snapshot();

        return response()->json([
            'operational' => $snapshot['operational'],
            'checkedAt' => $snapshot['checked_at'],
            'components' => $snapshot['components'],
            'reportUrl' => route('platform.status.report'),
        ]);
    }
}
