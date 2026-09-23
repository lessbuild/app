<?php

namespace App\Modules\Monitor\Database\Factories;

use App\Modules\Monitor\Models\TelemetryEvent;
use App\Modules\Monitor\Models\TelemetryEventIdentity;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TelemetryEventIdentity> */
class TelemetryEventIdentityFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'telemetry_event_id' => TelemetryEvent::factory(),
            'environment_id' => fn (array $attributes): int => TelemetryEvent::query()->findOrFail($attributes['telemetry_event_id'])->environment_id,
            'dedupe_key' => fn (array $attributes): string => TelemetryEvent::query()->findOrFail($attributes['telemetry_event_id'])->dedupe_key,
            'version' => 1,
            'payload_fingerprint' => null,
        ];
    }
}
