<?php

namespace App\Core\Data\Blueprints;

final readonly class BlueprintStepAttempt
{
    public function __construct(
        public string $runId,
        public string $stepId,
        public string $product,
        public BlueprintTarget $target,
        public array $configuration,
        public array $nativeAuthority,
        public string $payloadHash,
        public int $generation,
        public string $leaseToken,
    ) {}
}
