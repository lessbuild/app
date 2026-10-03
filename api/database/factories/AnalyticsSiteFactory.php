<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AnalyticsSite;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AnalyticsSite> */
class AnalyticsSiteFactory extends Factory
{
    protected $model = AnalyticsSite::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => 'Example',
            'domains' => ['example.com'],
            'timezone' => 'UTC',
            'verified_at' => now(),
            'collection_enabled' => true,
        ];
    }

    public function unverified(): static
    {
        return $this->state(['verified_at' => null]);
    }
}
