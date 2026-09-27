<?php

declare(strict_types=1);

namespace App\Data\Analytics;

final readonly class GoalDetails
{
    public function __construct(
        public string $name,
        /** path or event */
        public string $kind,
        /** exact or prefix */
        public string $matchType,
        public string $matchValue,
        public bool $active = true,
    ) {}
}
