<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\IssueStatus;
use App\Models\Issue;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Issue>
 */
class IssueFactory extends Factory
{
    protected $model = Issue::class;

    public function resolved(): static
    {
        return $this->state(fn (): array => ['status' => IssueStatus::Resolved, 'resolved_at' => now()]);
    }

    public function snoozed(): static
    {
        return $this->state(fn (): array => ['status' => IssueStatus::Snoozed, 'snoozed_until' => now()->addHour()]);
    }

    public function ignored(): static
    {
        return $this->state(['status' => IssueStatus::Ignored]);
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $seenAt = now();

        return [
            'project_id' => Project::factory()->withServices(['monitoring']),
            'environment_id' => null,
            'fingerprint' => hash('sha256', fake()->unique()->uuid()),
            'type' => 'exception',
            'severity' => 'error',
            'status' => 'open',
            'title' => fake()->sentence(4),
            'location' => '/health',
            'occurrences' => 1,
            'affected_users' => 0,
            'first_seen_at' => $seenAt,
            'last_seen_at' => $seenAt,
            'details' => null,
            'metadata' => [],
        ];
    }
}
