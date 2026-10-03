<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TelemetryEvent;
use App\Models\TelemetryEventIdentity;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TelemetryEventIdentity> */
class TelemetryEventIdentityFactory extends Factory
{
    protected $model = TelemetryEventIdentity::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'telemetry_event_id' => TelemetryEvent::factory(),
            'environment_id' => fn (array $attributes): string => TelemetryEvent::query()->whereKey($attributes['telemetry_event_id'])->firstOrFail()->environment_id,
            'dedupe_key' => fn (array $attributes): string => TelemetryEvent::query()->whereKey($attributes['telemetry_event_id'])->firstOrFail()->dedupe_key,
            'version' => 1,
            'payload_fingerprint' => null,
        ];
    }
}
