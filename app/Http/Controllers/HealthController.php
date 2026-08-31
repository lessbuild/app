<?php

namespace App\Http\Controllers;

use App\Services\ApplicationReadiness;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __invoke(ApplicationReadiness $readiness): JsonResponse
    {
        $ready = $readiness->isReady();

        return response()
            ->json(['status' => $ready ? 'ready' : 'unavailable'], $ready ? 200 : 503)
            ->header('Cache-Control', 'no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
