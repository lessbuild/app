<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Server;
use App\Models\Website;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Website> */
class WebsiteFactory extends Factory
{
    protected $model = Website::class;

    /**
     * An active website on an active app server.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'account_id' => fn (array $attributes): string => Server::query()->whereKey($attributes['server_id'])->valueOrFail('account_id'),
            'name' => 'Shop '.fake()->unique()->numberBetween(1, 99999),
            'url' => fake()->unique()->domainWord().'.example.com',
            'env_file' => "APP_ENV=production\n",
            'database_password' => 'db-secret',
            'setup_stage' => 3,
            'provisioning_status' => Website::STATUS_ACTIVE,
            'provisioned_at' => now(),
        ];
    }

    public function provisioning(string $status = Website::STATUS_PROVISIONING, int $stage = 0): static
    {
        return $this->state(['provisioning_status' => $status, 'setup_stage' => $stage, 'provisioned_at' => null]);
    }
}
