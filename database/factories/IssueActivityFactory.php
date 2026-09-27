<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Issue;
use App\Models\IssueActivity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IssueActivity>
 */
class IssueActivityFactory extends Factory
{
    protected $model = IssueActivity::class;

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
