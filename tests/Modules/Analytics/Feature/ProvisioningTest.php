<?php

namespace Tests\Modules\Analytics\Feature;

use App\Modules\Analytics\Enums\WorkspaceRole;
use App\Modules\Analytics\Models\User;
use Tests\Modules\Analytics\RefreshAnalyticsDatabase;
use Tests\TestCase;

class ProvisioningTest extends TestCase
{
    use RefreshAnalyticsDatabase;

    public function test_owner_provisioning_creates_a_verified_owner_workspace(): void
    {
        $this->artisan('analytics:provision-owner', [
            'email' => 'owner@example.com',
            '--name' => 'Analytics Owner',
            '--password' => 'long-enough-password',
        ])->assertSuccessful();

        $user = User::query()->sole();
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(WorkspaceRole::Owner, $user->defaultWorkspace()?->roleFor($user->getKey()));
    }
}
