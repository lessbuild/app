<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Build;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Build> */
class BuildFactory extends Factory
{
    protected $model = Build::class;

    /**
     * A queued manual build.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'repository_id' => Repository::factory(),
            'website_id' => fn (array $attributes): int => Repository::query()->whereKey($attributes['repository_id'])->valueOrFail('website_id'),
            'status' => Build::STATUS_QUEUED,
            'trigger_source' => 'manual',
        ];
    }

    /** A finished, live release still on the server. */
    public function succeeded(string $revision = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678'): static
    {
        return $this->state(fn (): array => [
            'status' => Build::STATUS_SUCCEEDED, 'revision' => $revision, 'setup_stage' => 15, 'started_at' => now()->subMinutes(3),
            'activated_at' => now()->subMinute(), 'finished_at' => now()->subMinute(),
        ])->afterCreating(fn (Build $build) => $build->forceFill(['release_name' => $build->releaseIdentifier(), 'release_path' => "/var/www/{$build->website->deployment_slug}/releases/{$build->releaseIdentifier()}"])->save());
    }
}
