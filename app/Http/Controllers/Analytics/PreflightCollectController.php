<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Models\AnalyticsSite;
use App\Support\Analytics\CollectionRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** OPTIONS /api/v1/collect/{publicId}: CORS preflight for the tracker. */
final class PreflightCollectController
{
    public function __invoke(Request $request, string $publicId): JsonResponse
    {
        $site = AnalyticsSite::query()->where('public_id', $publicId)->first();
        if ($site === null || ! CollectionRequest::originIsAllowed($request->header('Origin'), $site)) {
            return response()->json(['message' => 'Origin is not registered for this site.'], 403);
        }

        return response()->json(null, 204, CollectionRequest::corsHeaders($request));
    }
}
