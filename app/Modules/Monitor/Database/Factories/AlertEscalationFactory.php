<?php

namespace App\Modules\Monitor\Database\Factories;

use App\Modules\Monitor\Models\AlertDestination;
use App\Modules\Monitor\Models\AlertEscalation;
use App\Modules\Monitor\Models\AlertRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AlertEscalation>
 */
class AlertEscalationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'alert_rule_id' => AlertRule::factory(),
            'alert_destination_id' => AlertDestination::factory(),
            'delay_minutes' => 5,
            'position' => 0,
            'enabled' => true,
        ];
    }
}
