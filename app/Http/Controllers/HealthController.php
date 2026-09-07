<?php

namespace App\Http\Controllers;

use App\Services\ApplicationReadiness;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    /**
     * Return an uncached readiness response: HTTP 200 when ready, or HTTP 503 when unavailable.
     */
    public function __invoke(ApplicationReadiness $readiness): JsonResponse
    {
        $ready = $readiness->isReady();

        return response()
            ->json(['status' => $ready ? 'ready' : 'unavailable'], $ready ? 200 : 503)
            ->header('Cache-Control', 'no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
