<?php

namespace App\Modules\Monitor\Database\Factories;

use App\Modules\Monitor\Data\Telemetry\IngestSource;
use App\Modules\Monitor\Models\TelemetryUsageEntry;
use App\Modules\Monitor\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TelemetryUsageEntry> */
class TelemetryUsageEntryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'environment_id' => null,
            'ingest_receipt_id' => null,
            'source' => IngestSource::Json,
            'event_count' => 1,
            'received_at' => now('UTC'),
        ];
    }
}
