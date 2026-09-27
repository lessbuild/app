<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StatusPage;
use App\Models\StatusUpdate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StatusUpdate> */
class StatusUpdateFactory extends Factory
{
    protected $model = StatusUpdate::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'status_page_id' => StatusPage::factory(),
            'created_by' => null,
            'kind' => 'incident',
            'status' => 'investigating',
            'severity' => 'minor',
            'title' => 'Slow checkout',
            'message' => 'We are looking into slow responses at checkout.',
            'starts_at' => CarbonImmutable::now('UTC')->subMinutes(10),
            'ends_at' => null,
            'resolved_at' => null,
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn (): array => ['status' => 'resolved', 'resolved_at' => CarbonImmutable::now('UTC')]);
    }

    public function maintenance(): static
    {
        return $this->state(fn (): array => [
            'kind' => 'maintenance', 'status' => 'scheduled', 'title' => 'Database upgrade', 'message' => 'Checkout may be slow for a few minutes.',
            'starts_at' => CarbonImmutable::now('UTC')->addDay(), 'ends_at' => CarbonImmutable::now('UTC')->addDay()->addHour(),
        ]);
    }
}
