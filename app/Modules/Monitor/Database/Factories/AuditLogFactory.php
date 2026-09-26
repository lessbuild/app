<?php

namespace App\Modules\Monitor\Database\Factories;

use App\Modules\Monitor\Models\AuditLog;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AuditLog> */
class AuditLogFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'actor_id' => User::factory(),
            'action' => 'workspace.updated',
            'subject_type' => null,
            'subject_id' => null,
            'metadata' => ['label' => 'Platform workspace'],
            'ip_address' => '203.0.113.10',
            'user_agent' => 'Audit test client/1.0',
        ];
    }
}
