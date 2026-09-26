<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Services\MonitorApplicationReadiness;
use Illuminate\Http\JsonResponse;

final class ReadinessController extends Controller
{
    public function __invoke(MonitorApplicationReadiness $readiness): JsonResponse
    {
        $checks = $readiness->checks();
        $ready = collect($checks)->every(static fn (bool $check): bool => $check);

        return response()
            ->json(['status' => $ready ? 'ready' : 'unavailable', 'checks' => $checks], $ready ? 200 : 503)
            ->header('Cache-Control', 'no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
