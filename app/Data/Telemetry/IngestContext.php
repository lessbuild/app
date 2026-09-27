<?php

declare(strict_types=1);

namespace App\Data\Telemetry;

use App\Enums\IngestSource;

final readonly class IngestContext
{
    public function __construct(
        public IngestSource $source = IngestSource::Json,
        public bool $explicitBatch = true,
        public ?int $tokenId = null,
    ) {}
}
