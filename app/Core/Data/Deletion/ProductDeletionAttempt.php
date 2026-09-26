<?php

namespace App\Core\Data\Deletion;

final readonly class ProductDeletionAttempt
{
    public function __construct(
        public string $requestId,
        public string $stepId,
        public ProductDeletionTarget $target,
        public string $payloadHash,
        public int $generation,
        public string $leaseToken,
        public string $phase,
    ) {}
}
