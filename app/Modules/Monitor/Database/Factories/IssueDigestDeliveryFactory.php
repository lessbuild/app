<?php

namespace App\Modules\Monitor\Database\Factories;

use App\Modules\Monitor\Models\IssueDigestDelivery;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IssueDigestDelivery>
 */
class IssueDigestDeliveryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $periodEnd = CarbonImmutable::now('UTC');

        return [
            'workspace_id' => Workspace::factory(),
            'recipient_id' => User::factory(),
            'recipient_email' => fake()->safeEmail(),
            'period_start' => $periodEnd->subDay(),
            'period_end' => $periodEnd,
            'status' => IssueDigestDelivery::STATUS_SENT,
            'attempts' => 1,
            'new_count' => fake()->numberBetween(0, 10),
            'resolved_count' => fake()->numberBetween(0, 10),
            'open_count' => fake()->numberBetween(0, 20),
            'critical_open_count' => fake()->numberBetween(0, 3),
            'sent_at' => $periodEnd,
        ];
    }
}
