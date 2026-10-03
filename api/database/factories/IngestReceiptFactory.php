<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\IngestSource;
use App\Models\Environment;
use App\Models\IngestReceipt;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IngestReceipt> */
class IngestReceiptFactory extends Factory
{
    protected $model = IngestReceipt::class;

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
            'environment_id' => fn (): string => MonitorFactory::environment(),
            'account_id' => fn (array $attributes): string => Environment::query()->whereKey($attributes['environment_id'])->firstOrFail()->project->account_id,
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
