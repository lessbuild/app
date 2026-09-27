<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Data\Telemetry\ReleaseIdentity;
use App\Models\Project;
use App\Models\Release;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Release> */
class ReleaseFactory extends Factory
{
    protected $model = Release::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory()->withServices(['monitoring']), 'service' => 'app', 'service_namespace' => null,
            'version' => fake()->unique()->uuid(),
            'service_hash' => fn (array $attributes): string => (string) ReleaseIdentity::from($attributes['version'], $attributes['service'], $attributes['service_namespace'])?->serviceHash(),
            'version_hash' => fn (array $attributes): string => hash('sha256', $attributes['version']),
            'first_seen_at' => null, 'last_seen_at' => null,
        ];
    }
}
