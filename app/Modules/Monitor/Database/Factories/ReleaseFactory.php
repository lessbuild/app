<?php

namespace App\Modules\Monitor\Database\Factories;

use App\Modules\Monitor\Data\Telemetry\ReleaseIdentity;
use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Release;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Release> */
class ReleaseFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'application_id' => Application::factory(), 'service' => 'app', 'service_namespace' => null,
            'version' => fake()->unique()->uuid(),
            'service_hash' => fn (array $attributes): string => ReleaseIdentity::from($attributes['version'], $attributes['service'], $attributes['service_namespace'])->serviceHash(),
            'version_hash' => fn (array $attributes): string => hash('sha256', $attributes['version']),
            'first_seen_at' => null, 'last_seen_at' => null,
        ];
    }
}
