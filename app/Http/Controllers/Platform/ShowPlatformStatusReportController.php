<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Services\Admin\PlatformStatus;
use Illuminate\Http\JsonResponse;

final class ShowPlatformStatusReportController
{
    /**
     * Answer the platform status as JSON, in Core's shape, cached publicly for 30 seconds.
     *
     * @param  PlatformStatus  $status
     * @return JsonResponse
     */
    public function __invoke(PlatformStatus $status): JsonResponse
    {
        return response()->json($status->snapshot())->header('Cache-Control', 'public, max-age=30')->header('X-Content-Type-Options', 'nosniff');
    }
}
