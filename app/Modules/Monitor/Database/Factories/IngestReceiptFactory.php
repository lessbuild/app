<?php

namespace App\Modules\Monitor\Database\Factories;

use App\Modules\Monitor\Data\Telemetry\IngestSource;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\IngestReceipt;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IngestReceipt> */
class IngestReceiptFactory extends Factory
{
    public function queued(): static
    {
        return $this->state(fn (): array => [
            'status' => 'queued',
            'processed_at' => null,
            'next_attempt_at' => now('UTC'),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => 'failed',
            'processed_at' => null,
            'failed_at' => now('UTC'),
            'last_error_code' => 'processing_failed',
        ]);
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'environment_id' => Environment::factory(),
            'workspace_id' => fn (array $attributes): int => Environment::query()->findOrFail($attributes['environment_id'])->application->workspace_id,
            'receipt_key' => hash('sha256', fake()->unique()->uuid()),
            'payload_fingerprint' => hash('sha256', fake()->uuid()),
            'source' => IngestSource::Json,
            'status' => 'completed',
            'event_count' => 1,
            'accepted_count' => 1,
            'duplicate_count' => 0,
            'attempt_count' => 1,
            'received_at' => now('UTC'),
            'last_received_at' => now('UTC'),
            'processed_at' => now('UTC'),
        ];
    }
}
