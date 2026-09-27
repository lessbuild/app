<?php

declare(strict_types=1);

namespace App\Contracts\Telemetry;

use App\Data\Telemetry\IngestContext;
use App\Data\Telemetry\IngestResult;
use App\Models\Environment;

interface TelemetryIngestor
{
    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    public function ingest(Environment $environment, string $batchId, array $events, ?IngestContext $context = null): IngestResult;
}
