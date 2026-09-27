<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\IngestPayload;
use App\Models\IngestReceipt;
use App\Models\TelemetryEventIdentity;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IngestPayload> */
class IngestPayloadFactory extends Factory
{
    protected $model = IngestPayload::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ingest_receipt_id' => IngestReceipt::factory()->queued(),
            'payload' => function (array $attributes): array {
                $receipt = IngestReceipt::query()->whereKey($attributes['ingest_receipt_id'])->firstOrFail();
                $identity = TelemetryEventIdentity::factory()->create([
                    'environment_id' => $receipt->environment_id,
                    'ingest_receipt_id' => $receipt->id,
                    'telemetry_event_id' => null,
                    'dedupe_key' => hash('sha256', fake()->unique()->uuid()),
                    'payload_fingerprint' => hash('sha256', fake()->uuid()),
                    'version' => 2,
                ]);

                return [['identity_id' => $identity->id, 'event' => [
                    'type' => 'log', 'name' => 'Queued demonstration event',
                ]]];
            },
        ];
    }
}
