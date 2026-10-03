<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\StatusPageComponent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StatusPageComponent> */
class StatusPageComponentFactory extends Factory
{
    protected $model = StatusPageComponent::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'status_page_id' => StatusPage::factory(),
            'monitor_id' => Monitor::factory(),
            'label' => 'Website',
            'position' => 0,
        ];
    }
}
