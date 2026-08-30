<?php

namespace App\Data;

class VerifiedRepositoryWebhook
{
    public function __construct(
        public readonly string $deliveryId,
        public readonly bool $isPush,
        public readonly bool $matchesBranch,
    ) {}
}
