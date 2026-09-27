<?php

declare(strict_types=1);

namespace App\Data\Notifications;

use Carbon\CarbonImmutable;

final readonly class InboxItem
{
    public function __construct(
        public string $id,
        public string $title,
        public string $body,
        public bool $read,
        public CarbonImmutable $at,
    ) {}
}
