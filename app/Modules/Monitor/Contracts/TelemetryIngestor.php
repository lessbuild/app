<?php

namespace App\Modules\Monitor\Contracts;

use App\Modules\Monitor\Data\Telemetry\IngestContext;
use App\Modules\Monitor\Data\Telemetry\IngestResult;
use App\Modules\Monitor\Models\Environment;

interface TelemetryIngestor
{
    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    public function ingest(Environment $environment, string $batchId, array $events, ?IngestContext $context = null): IngestResult;
}
