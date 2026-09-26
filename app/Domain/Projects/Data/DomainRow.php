<?php

declare(strict_types=1);

namespace App\Domain\Projects\Data;

use Carbon\CarbonImmutable;

final readonly class DomainRow
{
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
