<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\AcceptEventBatch;
use App\Data\Analytics\NormalizedEvent;
use App\Http\Requests\Analytics\CollectEventsRequest;
use App\Models\AnalyticsSite;
use App\Support\Analytics\CollectionRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/** POST /api/v1/collect/{publicId}: the tracker's endpoint (a public contract; keep its behaviour unchanged). */
final class CollectEventsController
{
    /**
     * Accepts pageviews and custom events from the tracker. Unknown or paused sites get a 404, bots are accepted and
     * dropped, other origins are refused, excluded paths are skipped, and everything else is cleaned and queued for
     * processing.
     */
    public function __invoke(CollectEventsRequest $request, string $publicId, AcceptEventBatch $acceptEventBatch): JsonResponse
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
        /** @var list<array<string, mixed>> $input */
        $input = $request->validated('events');
        $events = [];
        foreach ($input as $event) {
            $path = parse_url((string) $event['path'], PHP_URL_PATH) ?: '/';
            $path = '/'.ltrim(Str::limit($path, 2048, ''), '/');
            $path = $path === '//' ? '/' : $path;
            if ($site->excludesPath($path)) {
                continue;
            }
            $events[] = new NormalizedEvent(
                eventId: (string) $event['id'],
                type: (string) $event['type'],
                // The server's receipt time, never the client's clock.
                occurredAt: $now,
                path: $path,
                referrerHost: CollectionRequest::cleanHost($event['referrer_host'] ?? null),
                utmSource: CollectionRequest::cleanValue($event['utm_source'] ?? null, 100),
                utmMedium: CollectionRequest::cleanValue($event['utm_medium'] ?? null, 100),
                utmCampaign: CollectionRequest::cleanValue($event['utm_campaign'] ?? null, 150),
                deviceCategory: CollectionRequest::cleanValue($event['device'] ?? null, 32),
                browser: CollectionRequest::cleanValue($event['browser'] ?? null, 64),
                operatingSystem: CollectionRequest::cleanValue($event['os'] ?? null, 64),
                // A daily-rotating hash: visitors can be counted within a day but not followed across days.
                visitorHash: hash_hmac('sha256', ($event['visitor'] ?? '').'|'.$request->ip().'|'.($request->userAgent() ?? '').'|'.$now->setTimezone($site->timezone)->toDateString(), (string) config('analytics.visitor_key')),
                sessionId: CollectionRequest::cleanValue($event['session'] ?? null, 64),
                properties: $event['type'] === 'event' ? CollectionRequest::safeProperties($event['properties'] ?? []) : null,
            );
        }

        return response()->json($acceptEventBatch->handle($site, $events), 202, CollectionRequest::corsHeaders($request));
    }
}
