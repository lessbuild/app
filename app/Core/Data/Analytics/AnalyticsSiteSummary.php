<?php

namespace App\Core\Data\Analytics;

use Carbon\CarbonImmutable;

final readonly class AnalyticsSiteSummary
{
    /** @param list<string> $domains @param list<string> $excludedPaths */
    public function __construct(
        public string $id,
        public string $name,
        public array $domains,
        public array $excludedPaths,
        public string $timezone,
        public bool $verified,
        public bool $collectionEnabled,
        public bool $collectionPaused,
        public bool $collectionAvailable,
        public bool $canManage,
        public bool $linkedToSharedProject,
        public bool $hasEvents,
        public ?CarbonImmutable $lastEventAt,
        public ?CarbonImmutable $lastProcessedAt,
        public bool $canDeleteSite = false,
        public ?string $deleteConfirmation = null,
    ) {}
}
