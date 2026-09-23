<?php

namespace App\Modules\Monitor\Database\Factories;

use App\Modules\Monitor\Models\MaintenanceWindow;
use App\Modules\Monitor\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaintenanceWindow>
 */
class MaintenanceWindowFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = CarbonImmutable::now('UTC')->addHour();

        return [
            'workspace_id' => Workspace::factory(),
            'created_by' => fn (array $attributes): int => Workspace::query()->findOrFail($attributes['workspace_id'])->owner_id,
            'name' => 'Planned deployment',
            'reason' => 'Deploying a new release.',
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHour(),
        ];
    }
}
