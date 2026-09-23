<?php

namespace App\Modules\Monitor\Database\Factories;

use App\Modules\Monitor\Models\IssueDigestPreference;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IssueDigestPreference>
 */
class IssueDigestPreferenceFactory extends Factory
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
            'user_id' => User::factory(),
            'enabled' => true,
            'frequency' => 'daily',
        ];
    }
}
