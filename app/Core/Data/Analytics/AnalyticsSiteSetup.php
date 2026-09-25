<?php

namespace App\Core\Data\Analytics;

final readonly class AnalyticsSiteSetup
{
    public function __construct(
        public string $id,
        public string $name,
        public string $publicId,
        public string $domain,
        public string $verificationRecordName,
        public string $verificationToken,
        public ?string $trackerSnippet,
        public bool $verified,
        public bool $hasEvents,
    ) {}
}
