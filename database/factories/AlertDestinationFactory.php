<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AccountRole;
use App\Enums\AlertDestinationType;
use App\Models\Account;
use App\Models\AlertDestination;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AlertDestination> */
class AlertDestinationFactory extends Factory
{
    protected $model = AlertDestination::class;

    public function email(): static
    {
        return $this->state(fn (): array => [
            'type' => AlertDestinationType::Email, 'endpoint_url' => null, 'signing_secret' => null,
            'recipient_user_id' => function (array $attributes): string {
                $member = Membership::query()->where('account_id', $attributes['account_id'])->orderBy('created_at')->value('user_id');
                if (is_string($member)) {
                    return $member;
                }
                $user = User::factory()->create();
                $membership = new Membership;
                $membership->forceFill(['account_id' => $attributes['account_id'], 'user_id' => $user->id, 'role' => AccountRole::Owner])->save();

                return $user->id;
            },
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
            'account_id' => Account::factory(), 'name' => 'Engineering alerts',
            'type' => AlertDestinationType::Webhook, 'endpoint_url' => 'https://alerts.example.com/events',
            'signing_secret' => Str::random(64), 'enabled' => true, 'target_revision' => 0, 'state_version' => 0,
        ];
    }
}
