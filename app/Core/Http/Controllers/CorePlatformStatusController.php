<?php

namespace App\Core\Http\Controllers;

use App\Core\Services\CorePlatformStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

final class CorePlatformStatusController
{
    public function show(CorePlatformStatus $status): Response
    {
        return response()->view('core::status.index', ['snapshot' => $status->snapshot()])
            ->header('Cache-Control', 'public, max-age=30')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public function report(CorePlatformStatus $status): JsonResponse
    {
        return response()->json($status->snapshot())
            ->header('Cache-Control', 'public, max-age=30')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
