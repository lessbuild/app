<?php

namespace App\Modules\Monitor\Database\Factories;

use App\Modules\Monitor\Models\UsageAlertDelivery;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<UsageAlertDelivery>
 */
class UsageAlertDeliveryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'recipient_id' => User::factory(),
            'recipient_email' => fake()->safeEmail(),
            'period_start' => Carbon::now('UTC')->startOfMonth(),
            'period_end' => Carbon::now('UTC')->startOfMonth()->addMonth(),
            'threshold' => 80,
            'status' => UsageAlertDelivery::STATUS_SENT,
            'attempts' => 1,
            'event_count' => 400_000,
            'event_limit' => 500_000,
            'percentage' => 80,
            'last_error_code' => null,
            'sending_started_at' => null,
            'sent_at' => Carbon::now('UTC'),
            'failed_at' => null,
        ];
    }
}
