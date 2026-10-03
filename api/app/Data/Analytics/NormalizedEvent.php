<?php

declare(strict_types=1);

namespace App\Data\Analytics;

use Carbon\CarbonImmutable;

final readonly class NormalizedEvent
{
    /**
     * Create a new NormalizedEvent instance.
     *
     * One pageview or custom event, cleaned up and ready to store.
     *
     * @param  string  $eventId  The client's ID for the event, so a resent batch doesn't count twice.
     * @param  string  $type  `pageview`, `event`, `vitals` or `engagement`.
     * @param  CarbonImmutable  $occurredAt  When we received it (never the client's clock).
     * @param  string  $path  The page path, without query string.
     * @param  ?string  $referrerHost  The site the visitor came from.
     * @param  ?string  $utmSource  The `utm_source` campaign tag.
     * @param  ?string  $utmMedium  The `utm_medium` campaign tag.
     * @param  ?string  $utmCampaign  The `utm_campaign` campaign tag.
     * @param  ?string  $deviceCategory  Desktop, mobile or tablet, as the tracker reported it.
     * @param  ?string  $browser  The browser's name.
     * @param  ?string  $operatingSystem  The operating system's name.
     * @param  ?string  $visitorHash  A hash that rotates daily, so visitors can be counted within a day but not followed
     *                                across days.
     * @param  ?string  $sessionId  The tracker's session ID, used to group a visit's pages.
     * @param  array<string, mixed>|null  $properties
     * @param  ?string  $countryCode  The visitor's country (ISO 3166 alpha-2), from their IP address, which isn't kept.
     * @param  ?string  $utmTerm  The `utm_term` campaign tag.
     * @param  ?string  $utmContent  The `utm_content` campaign tag.
     * @param  ?string  $channel  The marketing channel worked out from the tags and referrer.
     * @param  ?string  $screenSize  Mobile, Tablet, Laptop or Desktop, from the screen's width.
     * @param  ?string  $browserVersion  The browser's major version.
     * @param  ?string  $osVersion  The operating system's version.
     * @param  ?string  $region  The visitor's region (state or province), when the city database is installed.
     * @param  ?string  $city  The visitor's city, when the city database is installed.
     * @param  ?string  $returningHash  A stable per-site hash of the browser's ID, for retention; only when the snippet opts in.
     */
    public function __construct(
        public string $eventId,
        public string $type,
        public CarbonImmutable $occurredAt,
        public string $path,
        public ?string $referrerHost,
        public ?string $utmSource,
        public ?string $utmMedium,
        public ?string $utmCampaign,
        public ?string $deviceCategory,
        public ?string $browser,
        public ?string $operatingSystem,
        public ?string $visitorHash,
        public ?string $sessionId,
        public ?array $properties,
        public ?string $countryCode = null,
        public ?string $utmTerm = null,
        public ?string $utmContent = null,
        public ?string $channel = null,
        public ?string $screenSize = null,
        public ?string $browserVersion = null,
        public ?string $osVersion = null,
        public ?string $region = null,
        public ?string $city = null,
        public ?string $returningHash = null,
    ) {}

    /**
     * Convert the event to a row for `analytics_events`, for a bulk insert.
     *
     * @param  int  $siteId
     * @param  int  $batchId
     * @param  CarbonImmutable  $receivedAt
     * @return array<string, mixed>
     */
    public function toDatabase(int $siteId, int $batchId, CarbonImmutable $receivedAt): array
    {
        return [
            'site_id' => $siteId,
            'ingestion_batch_id' => $batchId,
            'event_id' => $this->eventId,
            'type' => $this->type,
            'occurred_at' => $this->occurredAt,
            'received_at' => $receivedAt,
            'path' => $this->path,
            'referrer_host' => $this->referrerHost,
            'utm_source' => $this->utmSource,
            'utm_medium' => $this->utmMedium,
            'utm_campaign' => $this->utmCampaign,
            'device_category' => $this->deviceCategory,
            'browser' => $this->browser,
            'operating_system' => $this->operatingSystem,
            'country_code' => $this->countryCode,
            'utm_term' => $this->utmTerm,
            'utm_content' => $this->utmContent,
            'channel' => $this->channel,
            'screen_size' => $this->screenSize,
            'browser_version' => $this->browserVersion,
            'os_version' => $this->osVersion,
            'region' => $this->region,
            'city' => $this->city,
            'returning_hash' => $this->returningHash,
            'visitor_hash' => $this->visitorHash,
            'session_id' => $this->sessionId,
            'properties' => $this->properties ? json_encode($this->properties, JSON_THROW_ON_ERROR) : null,
            'created_at' => $receivedAt,
            'updated_at' => $receivedAt,
        ];
    }
}
