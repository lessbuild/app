<?php

namespace App\Modules\Analytics\Http\Controllers\Collection;

use App\Modules\Analytics\Actions\Collection\AcceptEventBatch;
use App\Modules\Analytics\Data\NormalizedEvent;
use App\Modules\Analytics\Http\Controllers\Controller;
use App\Modules\Analytics\Http\Requests\Collection\CollectEventsRequest;
use App\Modules\Analytics\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CollectEventsController extends Controller
{
    public function preflight(Request $request, string $publicId): JsonResponse
    {
        $site = Site::query()->where('public_id', $publicId)->first();

        if (! $site || ! $this->originIsAllowed($request->header('Origin'), $site)) {
            return response()->json(['message' => 'Origin is not registered for this site.'], 403);
        }

        return response()->json(null, 204, $this->corsHeaders($request));
    }

    public function __invoke(CollectEventsRequest $request, string $publicId, AcceptEventBatch $acceptEventBatch): JsonResponse
    {
        $site = Site::query()->where('public_id', $publicId)->first();

        if (! $site || ! $site->isCollectionAvailable()) {
            return response()->json(['message' => 'Collection is not available for this site.'], 404);
        }

        if ($this->isBot($request->userAgent())) {
            return response()->json(['batch_id' => null, 'accepted' => 0], 202, $this->corsHeaders($request));
        }

        if (! $this->originIsAllowed($request->header('Origin'), $site)) {
            return response()->json(['message' => 'Origin is not registered for this site.'], 403);
        }

        $now = CarbonImmutable::now();
        $events = collect($request->validated('events'))->map(function (array $event) use ($request, $now, $site): ?NormalizedEvent {
            $path = parse_url($event['path'], PHP_URL_PATH) ?: '/';
            $path = '/'.ltrim(Str::limit($path, 2048, ''), '/');

            if ($site->excludesPath($path === '//' ? '/' : $path)) {
                return null;
            }

            return new NormalizedEvent(
                eventId: $event['id'],
                type: $event['type'],
                occurredAt: $now,
                path: $path === '//' ? '/' : $path,
                referrerHost: $this->cleanHost($event['referrer_host'] ?? null),
                utmSource: $this->cleanValue($event['utm_source'] ?? null, 100),
                utmMedium: $this->cleanValue($event['utm_medium'] ?? null, 100),
                utmCampaign: $this->cleanValue($event['utm_campaign'] ?? null, 150),
                deviceCategory: $this->cleanValue($event['device'] ?? null, 32),
                browser: $this->cleanValue($event['browser'] ?? null, 64),
                operatingSystem: $this->cleanValue($event['os'] ?? null, 64),
                visitorHash: hash_hmac('sha256', ($event['visitor'] ?? '').'|'.$request->ip().'|'.($request->userAgent() ?? '').'|'.$now->setTimezone($site->timezone)->toDateString(), (string) config('analytics.visitor_key')),
                sessionId: $this->cleanValue($event['session'] ?? null, 64),
                properties: $event['type'] === 'event' ? $this->safeProperties($event['properties'] ?? []) : null,
            );
        })->filter()->values()->all();

        $result = $acceptEventBatch->handle($site, $events);

        return response()->json($result, 202, $this->corsHeaders($request));
    }

    private function originIsAllowed(?string $origin, Site $site): bool
    {
        if (! $origin) {
            return true;
        }

        $host = parse_url($origin, PHP_URL_HOST);

        return $host && collect((array) $site->domains)->contains(fn (string $domain): bool => strcasecmp($domain, $host) === 0);
    }

    private function cleanHost(?string $host): ?string
    {
        $host = trim((string) $host);

        return $host !== '' && preg_match('/^[a-z0-9.-]+$/i', $host) ? strtolower(Str::limit($host, 255, '')) : null;
    }

    private function isBot(?string $userAgent): bool
    {
        return $userAgent !== null && preg_match('/bot|crawler|spider|slurp|bingpreview|headless/i', $userAgent) === 1;
    }

    private function cleanValue(?string $value, int $length): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? Str::limit($value, $length, '') : null;
    }

    private function safeProperties(array $properties): array
    {
        $name = $properties['name'] ?? null;

        return is_string($name) && preg_match('/^[a-z0-9][a-z0-9_.-]{0,79}$/i', $name) === 1
            ? ['name' => $name]
            : [];
    }

    /** @return array<string, string> */
    private function corsHeaders(Request $request): array
    {
        return [
            'Access-Control-Allow-Origin' => $request->header('Origin') ?: '*',
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type',
            'Access-Control-Max-Age' => '600',
            'Vary' => 'Origin',
        ];
    }
}
