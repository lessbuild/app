<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProviderType;
use App\Models\Account;
use App\Models\Provider;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Provider> */
class ProviderFactory extends Factory
{
    protected $model = Provider::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'created_by' => null,
            'name' => 'Production cloud',
            'type' => ProviderType::DigitalOcean,
            'token' => 'do-token-'.fake()->uuid(),
            'connection_status' => 'unchecked',
        ];
    }

    public function type(ProviderType $type): static
    {
        return $this->state(['type' => $type]);
    }
}
