<?php

namespace App\Modules\Monitor\Database\Factories;

use App\Modules\Monitor\Data\Telemetry\IssueStatus;
use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Issue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Issue>
 */
class IssueFactory extends Factory
{
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
            'application_id' => Application::factory(),
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
