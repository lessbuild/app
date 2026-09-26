<?php

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Services\AnalyticsApplicationReadiness;
use Illuminate\Http\JsonResponse;

class ReadinessController extends Controller
{
    public function __invoke(AnalyticsApplicationReadiness $readiness): JsonResponse
    {
        $checks = $readiness->checks();
        $ready = collect($checks)->every(static fn (bool $check): bool => $check);

        return response()
            ->json(['status' => $ready ? 'ok' : 'not_ready', 'checks' => $checks], $ready ? 200 : 503)
            ->header('Cache-Control', 'no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
