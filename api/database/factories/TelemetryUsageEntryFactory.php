<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\IngestSource;
use App\Models\Account;
use App\Models\TelemetryUsageEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TelemetryUsageEntry> */
class TelemetryUsageEntryFactory extends Factory
{
    protected $model = TelemetryUsageEntry::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'environment_id' => null,
            'ingest_receipt_id' => null,
            'source' => IngestSource::Json,
            'event_count' => 1,
            'received_at' => now('UTC'),
        ];
    }
}
