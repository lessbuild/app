<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ServerType;
use App\Models\Provider;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Server> */
class ServerFactory extends Factory
{
    protected $model = Server::class;

    /**
     * An active DigitalOcean app server.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider_id' => Provider::factory(),
            'account_id' => fn (array $attributes): string => Provider::query()->whereKey($attributes['provider_id'])->valueOrFail('account_id'),
            'type' => ServerType::App,
            'name' => 'web-'.fake()->unique()->numberBetween(1, 99999),
            'identifier' => (string) fake()->numberBetween(1000, 999999),
            'region' => 'fra1',
            'size' => 's-1vcpu-1gb',
            'image' => 'ubuntu-24-04-x64',
            'public_ip' => fake()->ipv4(),
            'ssh_port' => 22,
            'ssh_public_key' => 'ssh-rsa AAAATEST',
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
            'ssh_host_key' => '203.0.113.1 ssh-ed25519 AAAATEST',
            'mysql_root_password' => 'mysql-secret',
            'setup_stage' => 12,
            'provisioning_status' => Server::STATUS_ACTIVE,
            'provisioned_at' => now(),
        ];
    }

    public function provisioning(string $status = Server::STATUS_PROVISIONING, int $stage = 0): static
    {
        return $this->state(['provisioning_status' => $status, 'setup_stage' => $stage, 'provisioned_at' => null]);
    }

    public function failed(string $phase): static
    {
        return $this->state(['provisioning_status' => Server::STATUS_FAILED, 'provisioning_failure_phase' => $phase, 'provisioning_error' => 'Boom', 'provisioned_at' => null]);
    }
}
