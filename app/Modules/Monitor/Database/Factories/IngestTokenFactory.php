<?php

namespace App\Modules\Monitor\Database\Factories;

use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\IngestToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<IngestToken> */
class IngestTokenFactory extends Factory
{
    public function definition(): array
    {
        return [
            'environment_id' => Environment::factory(),
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
