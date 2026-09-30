<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\AcceptEventBatch;
use App\Contracts\Analytics\CountryLookup;
use App\Http\Requests\Analytics\CollectEventsRequest;
use App\Models\AnalyticsSite;
use App\Services\Analytics\EventNormalizer;
use App\Support\Analytics\CollectionRequest;
use App\Support\IpRanges;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/** POST /api/v1/collect/{publicId}: the tracker's endpoint (a public contract; keep its behaviour unchanged). */
final class CollectEventsController
{
    /**
     * Accept pageviews and custom events from the tracker. Unknown or paused sites get a 404, bots are accepted and
     * dropped, other origins are refused, excluded paths are skipped, and everything else is cleaned and queued for
     * processing.
     *
     * @param  CollectEventsRequest  $request
     * @param  string  $publicId
     * @param  AcceptEventBatch  $acceptEventBatch
     * @param  CountryLookup  $countries
     * @param  EventNormalizer  $normalizer
     * @return JsonResponse
     */
    public function __invoke(CollectEventsRequest $request, string $publicId, AcceptEventBatch $acceptEventBatch, CountryLookup $countries, EventNormalizer $normalizer): JsonResponse
    {
        $site = AnalyticsSite::query()->where('public_id', $publicId)->first();
        if ($site === null || ! $site->isCollectionAvailable()) {
            return response()->json(['message' => 'Collection is not available for this site.'], 404);
        }
        if (CollectionRequest::isBot($request->userAgent())) {
            return response()->json(['batch_id' => null, 'accepted' => 0], 202, CollectionRequest::corsHeaders($request));
        }
        if (! CollectionRequest::originIsAllowed($request->header('Origin'), $site)) {
            return response()->json(['message' => 'Origin is not registered for this site.'], 403);
        }

        $now = CarbonImmutable::now();
        $ip = CollectionRequest::clientIp($request);
        if ($ip !== null && IpRanges::contains($site->excluded_ips ?? [], $ip)) {
            return response()->json(['batch_id' => null, 'accepted' => 0], 202, CollectionRequest::corsHeaders($request));
        }
        $location = $countries->location($ip);
        /** @var list<array<string, mixed>> $input */
        $input = $request->validated('events');
        $events = [];
        foreach ($input as $event) {
            $normalized = $normalizer->normalize($site, $event, $now, $ip, $request->userAgent(), $location);
            if ($normalized !== null) {
                $events[] = $normalized;
            }
        }

        return response()->json($acceptEventBatch->handle($site, $events), 202, CollectionRequest::corsHeaders($request));
    }
}
