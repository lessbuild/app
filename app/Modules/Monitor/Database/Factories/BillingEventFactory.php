<?php

namespace App\Modules\Monitor\Database\Factories;

use App\Modules\Monitor\Models\BillingEvent;
use App\Modules\Monitor\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillingEvent>
 */
class BillingEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stripe_event_id' => 'evt_'.fake()->unique()->bothify('??????????????????'),
            'event_type' => fake()->randomElement([
                'checkout.session.completed',
                'customer.subscription.updated',
                'invoice.paid',
            ]),
            'workspace_id' => Workspace::factory(),
            'processed_at' => now(),
        ];
    }
}
