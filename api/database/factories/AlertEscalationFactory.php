<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AlertDestination;
use App\Models\AlertEscalation;
use App\Models\AlertRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AlertEscalation> */
class AlertEscalationFactory extends Factory
{
    protected $model = AlertEscalation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'alert_rule_id' => AlertRule::factory(),
            // A destination in the rule's account.
            'alert_destination_id' => fn (array $attributes): int => AlertDestination::factory()->create([
                'account_id' => AlertRule::query()->whereKey($attributes['alert_rule_id'])->firstOrFail()->environment->project->account_id,
            ])->id,
            'delay_minutes' => 5,
            'position' => 0,
            'enabled' => true,
        ];
    }
}
