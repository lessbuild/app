<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Data\Analytics\NormalizedEvent;
use App\Models\AnalyticsSite;
use App\Support\Analytics\Channel;
use App\Support\Analytics\CollectionRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Cleans one incoming event (from the tracker or the server-side API) into what's stored: the page path, campaign
 * tags, channel, device details, a daily-rotating visitor hash, the allowed properties and the visitor's location.
 */
final class EventNormalizer
{
    /**
     * Clean an event, or return null when its page is one the site ignores.
     *
     * @param  AnalyticsSite  $site
     * @param  array<string, mixed>  $event  validated input
     * @param  CarbonImmutable  $now  when it was received (the time recorded, never the sender's clock)
     * @param  string|null  $ip  the visitor's address, only used for the hash and location
     * @param  string|null  $userAgent
     * @param  array{country: string|null, region: string|null, city: string|null}  $location
     * @return NormalizedEvent|null
     */
    public function normalize(AnalyticsSite $site, array $event, CarbonImmutable $now, ?string $ip, ?string $userAgent, array $location): ?NormalizedEvent
    {
        $rawPath = (string) $event['path'];
        $path = parse_url($rawPath, PHP_URL_PATH) ?: '/';
        // Sites that route with #hash (data-hash on the snippet) keep the fragment as part of the page.
        $fragment = ($event['hash'] ?? false) === true ? parse_url($rawPath, PHP_URL_FRAGMENT) : null;
        if (is_string($fragment) && $fragment !== '') {
            $path .= '#'.explode('?', $fragment, 2)[0];
        }
        $path = '/'.ltrim(Str::limit($path, 2048, ''), '/');
        $path = $path === '//' ? '/' : $path;
        if ($site->excludesPath($path)) {
            return null;
        }
        $referrer = CollectionRequest::cleanHost($event['referrer_host'] ?? null);

        return new NormalizedEvent(
            eventId: (string) $event['id'],
            type: (string) $event['type'],
            occurredAt: $now,
            path: $path,
            referrerHost: $referrer,
            utmSource: CollectionRequest::cleanValue($event['utm_source'] ?? null, 100),
            utmMedium: CollectionRequest::cleanValue($event['utm_medium'] ?? null, 100),
            utmCampaign: CollectionRequest::cleanValue($event['utm_campaign'] ?? null, 150),
            deviceCategory: CollectionRequest::cleanValue($event['device'] ?? null, 32),
            browser: CollectionRequest::cleanValue($event['browser'] ?? null, 64),
            operatingSystem: CollectionRequest::cleanValue($event['os'] ?? null, 64),
            // A daily-rotating hash: visitors can be counted within a day but not followed across days.
            visitorHash: hash_hmac('sha256', ($event['visitor'] ?? '').'|'.$ip.'|'.($userAgent ?? '').'|'.$now->setTimezone($site->timezone)->toDateString(), (string) config('analytics.visitor_key')),
            sessionId: CollectionRequest::cleanValue($event['session'] ?? null, 64),
            properties: match ($event['type']) {
                'event' => CollectionRequest::safeProperties($event['properties'] ?? [], $site->custom_properties ?? []),
                'vitals' => CollectionRequest::safeVitals($event['properties'] ?? []) ?: null,
                'engagement' => CollectionRequest::safeEngagement($event['properties'] ?? []),
                'click' => CollectionRequest::safeClick($event['properties'] ?? []),
                'form' => CollectionRequest::safeForm($event['properties'] ?? []),
                'pageview' => ($search = CollectionRequest::searchTerm($event['search'] ?? null)) === null ? null : ['search' => $search],
                default => null,
            },
            countryCode: $location['country'],
            utmTerm: CollectionRequest::cleanValue($event['utm_term'] ?? null, 150),
            utmContent: CollectionRequest::cleanValue($event['utm_content'] ?? null, 150),
            channel: Channel::for($event['utm_source'] ?? null, $event['utm_medium'] ?? null, $event['utm_campaign'] ?? null, $referrer, $site->domains),
            screenSize: CollectionRequest::screenSize($event['screen'] ?? null),
            browserVersion: CollectionRequest::version($event['browser_version'] ?? null),
            osVersion: CollectionRequest::version($event['os_version'] ?? null),
            region: $location['region'],
            city: $location['city'],
            // Only sent when the snippet has data-retention: a random ID the browser keeps, hashed per site.
            returningHash: is_string($event['returning'] ?? null) && $event['returning'] !== ''
                ? hash_hmac('sha256', $site->id.'|'.$event['returning'], (string) config('analytics.visitor_key')) : null,
        );
    }
}
