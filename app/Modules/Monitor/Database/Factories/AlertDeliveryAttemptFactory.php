<?php

namespace App\Modules\Monitor\Database\Factories;

use App\Modules\Monitor\Models\AlertDelivery;
use App\Modules\Monitor\Models\AlertDeliveryAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AlertDeliveryAttempt> */
class AlertDeliveryAttemptFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'alert_delivery_id' => AlertDelivery::factory(), 'number' => 1, 'status' => 'sending', 'started_at' => now('UTC'),
        ];
    }
}
