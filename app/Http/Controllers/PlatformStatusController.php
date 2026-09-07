<?php

namespace App\Http\Controllers;

use App\Services\PublicPlatformStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class PlatformStatusController extends Controller
{
    /**
     * Render the public platform snapshot with a 30-second shared-cache lifetime.
     */
    public function show(PublicPlatformStatus $platformStatus): Response
    {
        return response()->view('status.platform', ['snapshot' => $platformStatus->snapshot()])
            ->header('Cache-Control', 'public, max-age=30')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    /**
     * Return the public platform snapshot as JSON with a 30-second shared-cache lifetime.
     */
    public function report(PublicPlatformStatus $platformStatus): JsonResponse
    {
        return response()->json($platformStatus->snapshot())
            ->header('Cache-Control', 'public, max-age=30')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
