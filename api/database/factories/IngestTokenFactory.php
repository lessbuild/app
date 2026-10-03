<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\IngestToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<IngestToken> */
class IngestTokenFactory extends Factory
{
    protected $model = IngestToken::class;

    public function definition(): array
    {
        return [
            'environment_id' => fn (): string => MonitorFactory::environment(),
            'name' => 'Test collector',
            'token_hash' => hash('sha256', Str::random(64)),
            'prefix' => 'bcn_test',
            'expires_at' => null,
        ];
    }

    public function withSecret(string $secret): static
    {
        return $this->state(fn (): array => ['token_hash' => hash('sha256', $secret), 'prefix' => substr($secret, 0, 12)]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => ['revoked_at' => now()]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subMinute()]);
    }
}
