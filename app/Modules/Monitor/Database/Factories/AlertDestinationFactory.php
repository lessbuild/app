<?php

namespace App\Modules\Monitor\Database\Factories;

use App\Modules\Monitor\Data\Telemetry\AlertDestinationType;
use App\Modules\Monitor\Models\AlertDestination;
use App\Modules\Monitor\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AlertDestination> */
class AlertDestinationFactory extends Factory
{
    public function email(): static
    {
        return $this->state(fn (): array => [
            'type' => AlertDestinationType::Email, 'endpoint_url' => null, 'signing_secret' => null,
            'recipient_user_id' => fn (array $attributes): int => Workspace::query()->findOrFail($attributes['workspace_id'])->owner_id,
        ]);
    }

    public function slack(): static
    {
        return $this->state(fn (): array => [
            'type' => AlertDestinationType::Slack, 'endpoint_url' => 'https://hooks.slack.com/services/T123/B456/FixtureSecret', 'signing_secret' => null,
        ]);
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(), 'name' => 'Engineering alerts',
            'type' => AlertDestinationType::Webhook, 'endpoint_url' => 'https://alerts.example.com/events',
            'signing_secret' => Str::random(64), 'enabled' => true, 'target_revision' => 0, 'state_version' => 0,
        ];
    }
}
