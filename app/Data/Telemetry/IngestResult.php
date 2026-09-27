<?php

declare(strict_types=1);

namespace App\Data\Telemetry;

use App\Enums\IngestStatus;

final readonly class IngestResult
{
    /**
     * Create a new class instance.
     */
    public function __construct(
        public string $batchId,
        public int $accepted,
        public int $duplicates,
        public ?string $receiptId = null,
        public bool $replayed = false,
        public IngestStatus $status = IngestStatus::Completed,
    ) {}
}
