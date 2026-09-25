<?php

namespace App\Core\Data\Analytics;

final readonly class AnalyticsSiteSettings
{
    /** @param list<string> $domains @param list<string> $excludedPaths */
    public function __construct(
        public string $name,
        public array $domains,
        public string $timezone,
        public array $excludedPaths,
        public bool $collectionEnabled,
        public bool $collectionPaused,
    ) {}
}
