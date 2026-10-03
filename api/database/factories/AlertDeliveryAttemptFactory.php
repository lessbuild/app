<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AlertDelivery;
use App\Models\AlertDeliveryAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AlertDeliveryAttempt> */
class AlertDeliveryAttemptFactory extends Factory
{
    protected $model = AlertDeliveryAttempt::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'alert_delivery_id' => AlertDelivery::factory(), 'number' => 1, 'status' => 'sending', 'started_at' => now('UTC'),
        ];
    }
}
