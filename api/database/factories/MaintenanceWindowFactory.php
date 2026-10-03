<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Account;
use App\Models\MaintenanceWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MaintenanceWindow> */
class MaintenanceWindowFactory extends Factory
{
    protected $model = MaintenanceWindow::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = CarbonImmutable::now('UTC')->addHour();

        return [
            'account_id' => Account::factory(),
            'created_by' => null,
            'name' => 'Planned deployment',
            'reason' => 'Deploying a new release.',
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHour(),
        ];
    }
}
