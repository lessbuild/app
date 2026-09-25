<?php

namespace App\Core\Data\Restoration;

final readonly class ResourceRestorationAttempt
{
    public function __construct(
        public string $requestId,
        public string $actorId,
        public ResourceRestorationTarget $target,
        public int $expectedRevision,
        public string $mappingFingerprint,
        public string $payloadHash,
        public int $generation,
        public string $leaseToken,
    ) {}
}
