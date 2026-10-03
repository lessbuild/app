<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Account;
use App\Models\Dashboard;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Dashboard> */
class DashboardFactory extends Factory
{
    protected $model = Dashboard::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['account_id' => Account::factory(), 'created_by' => null, 'name' => 'Production', 'description' => null, 'range' => '24h'];
    }

    /** @param list<string> $types */
    public function withWidgets(array $types = ['telemetry', 'incidents']): static
    {
        return $this->afterCreating(function (Dashboard $dashboard) use ($types): void {
            foreach ($types as $position => $type) {
                $dashboard->widgets()->create(['type' => $type, 'position' => $position]);
            }
        });
    }
}
