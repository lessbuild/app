<?php

namespace App\Modules\Monitor\Database\Factories;

use App\Modules\Monitor\Models\Deployment;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\Release;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Deployment> */
class DeploymentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'environment_id' => Environment::factory(),
            'release_id' => fn (array $attributes): int => Release::factory()->create([
                'application_id' => Environment::query()->findOrFail($attributes['environment_id'])->application_id,
            ])->id,
            'deployment_key' => fake()->unique()->uuid(), 'payload_hash' => hash('sha256', fake()->uuid()),
            'source' => 'manual', 'commit_sha' => null, 'note' => null, 'deployed_at' => now(),
        ];
    }
}
