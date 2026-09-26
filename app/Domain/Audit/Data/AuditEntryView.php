<?php

declare(strict_types=1);

namespace App\Domain\Audit\Data;

use Carbon\CarbonImmutable;

final readonly class AuditEntryView
{
    public function __construct(
        public string $id,
        public string $actor,
        public ?string $actorEmail,
        public string $description,
        public ?string $ipAddress,
        public ?string $device,
        public CarbonImmutable $at,
    ) {}
}
