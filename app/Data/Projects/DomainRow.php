<?php

declare(strict_types=1);

namespace App\Data\Projects;

use Carbon\CarbonImmutable;

final readonly class DomainRow
{
    /**
     * One domain on a project's domains page, with what's needed to verify it.
     *
     * @param  string  $id  The domain's ID.
     * @param  string  $name  The domain as people read it (Unicode, not punycode).
     * @param  ?string  $environment  The environment it points at, by name.
     * @param  ?CarbonImmutable  $verifiedAt  When ownership was proven; null while unverified.
     * @param  ?CarbonImmutable  $lastCheckedAt  When we last looked for the TXT record.
     * @param  string  $recordName  The TXT record's name to create at the DNS host.
     * @param  string  $recordValue  The TXT record's value.
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $environment,
        public ?CarbonImmutable $verifiedAt,
        public ?CarbonImmutable $lastCheckedAt,
        public string $recordName,
        public string $recordValue,
    ) {}
}
