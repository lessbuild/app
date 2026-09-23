<?php

namespace App\Modules\Monitor\Database\Factories;

use App\Modules\Monitor\Models\Issue;
use App\Modules\Monitor\Models\IssueActivity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IssueActivity>
 */
class IssueActivityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'issue_id' => Issue::factory(),
            'actor_id' => null,
            'action' => 'detected',
            'metadata' => ['status' => 'open'],
            'note' => null,
        ];
    }
}
