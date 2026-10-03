<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Release;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Deployment> */
class DeploymentFactory extends Factory
{
    protected $model = Deployment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'environment_id' => fn (): string => MonitorFactory::environment(),
            'release_id' => fn (array $attributes): int => Release::factory()->create([
                'project_id' => Environment::query()->whereKey($attributes['environment_id'])->firstOrFail()->project_id,
            ])->id,
            'deployment_key' => fake()->unique()->uuid(), 'payload_hash' => hash('sha256', fake()->uuid()),
            'source' => 'manual', 'commit_sha' => null, 'note' => null, 'deployed_at' => now(),
        ];
    }
}
