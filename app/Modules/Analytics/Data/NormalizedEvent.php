<?php

namespace App\Modules\Analytics\Data;

use Carbon\CarbonImmutable;

final readonly class NormalizedEvent
{
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
    ) {}

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
            'visitor_hash' => $this->visitorHash,
            'session_id' => $this->sessionId,
            'properties' => $this->properties ? json_encode($this->properties, JSON_THROW_ON_ERROR) : null,
            'created_at' => $receivedAt,
            'updated_at' => $receivedAt,
        ];
    }
}
