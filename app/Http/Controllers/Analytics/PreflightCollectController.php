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
    /**
     * Answer the browser's CORS preflight for the collection endpoint, allowing only the site's own origins.
     *
     * @param  Request  $request
     * @param  string  $publicId
     * @return JsonResponse
     */
    public function __invoke(Request $request, string $publicId): JsonResponse
    {
        $site = AnalyticsSite::query()->where('public_id', $publicId)->first();
        if ($site === null || ! CollectionRequest::originIsAllowed($request->header('Origin'), $site)) {
            return response()->json(['message' => 'Origin is not registered for this site.'], 403);
        }

        return response()->json(null, 204, CollectionRequest::corsHeaders($request));
    }
}
