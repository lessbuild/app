<?php

namespace App\Modules\Monitor\Database\Factories;

use App\Modules\Monitor\Models\Dashboard;
use App\Modules\Monitor\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Dashboard>
 */
class DashboardFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'created_by' => fn (array $attributes): int => Workspace::query()->findOrFail($attributes['workspace_id'])->owner_id,
            'name' => 'Operations overview',
            'description' => 'A shared view of production health.',
            'range' => '24h',
        ];
    }
}
