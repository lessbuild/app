<?php

declare(strict_types=1);

namespace App\Data\Telemetry;

use App\Enums\IngestStatus;

final readonly class IngestResult
{
    /**
     * Create a new class instance.
     *
     * @param  string  $batchId  The batch's ID, echoed back.
     * @param  int  $accepted  How many events were stored.
     * @param  int  $duplicates  How many were skipped because we already had them.
     * @param  ?string  $receiptId  The receipt to look the batch up by, when one was recorded.
     * @param  bool  $replayed  Whether the whole batch was a repeat of one already processed, so the stored result was
     *                          returned.
     * @param  IngestStatus  $status  Whether processing finished, is queued, or failed.
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
