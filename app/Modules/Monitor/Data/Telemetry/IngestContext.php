<?php

namespace App\Modules\Monitor\Data\Telemetry;

final readonly class IngestContext
{
    public function __construct(
        public IngestSource $source = IngestSource::Json,
        public bool $explicitBatch = true,
        public ?int $tokenId = null,
    ) {}
}
