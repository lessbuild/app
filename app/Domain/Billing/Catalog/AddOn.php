<?php

declare(strict_types=1);

namespace App\Domain\Billing\Catalog;

final readonly class AddOn
{
    /** @param array<string, int> $grants per unit bought: entitlement key => extra amount */
    public function __construct(
        public string $key,
        public string $name,
        public ?int $monthlyCentsPerUnit,
        public string $description,
        public array $grants,
        public int $maxQuantity = 100,
    ) {}
}
