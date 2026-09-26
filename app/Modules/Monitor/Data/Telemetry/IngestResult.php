<?php

namespace App\Modules\Monitor\Data\Telemetry;

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
