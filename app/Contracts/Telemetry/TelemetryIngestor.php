<?php

declare(strict_types=1);

namespace App\Contracts\Telemetry;

use App\Data\Telemetry\IngestContext;
use App\Data\Telemetry\IngestResult;
use App\Models\Environment;

interface TelemetryIngestor
{
    /**
     * Stores a batch of already-mapped events for an environment. Validates sizes, fingerprints errors into issues and
     * writes the rows; `$batchId` identifies the batch in the result so the caller can report what was accepted.
     *
     * @param  Environment  $environment
     * @param  string  $batchId
     * @param  array<int, array<string, mixed>>  $events
     * @param  IngestContext|null  $context
     * @return IngestResult
     */
    public function ingest(Environment $environment, string $batchId, array $events, ?IngestContext $context = null): IngestResult;
}
