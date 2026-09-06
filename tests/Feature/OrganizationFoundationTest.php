<?php

namespace Tests\Feature;

use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Services\PersonalOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_personal_organization_is_created_idempotently(): void
    {
        $user = User::factory()->create(['name' => 'Ada Lovelace']);
        $service = app(PersonalOrganization::class);

        $first = $service->ensure($user);
        $second = $service->ensure($user->fresh());

        $this->assertTrue($first->is($second));
        $this->assertSame('Ada Lovelace Workspace', $first->name);
        $this->assertSame('owner', $first->roleFor($user));
        $this->assertSame($first->id, $user->fresh()->current_organization_id);
        $this->assertDatabaseCount('organizations', 1);
    }

    public function test_roles_have_expected_capabilities(): void
    {
        $owner = User::factory()->create();
        $organization = app(PersonalOrganization::class)->ensure($owner);

        foreach (['admin', 'developer', 'viewer'] as $role) {
            $member = User::factory()->create();
            $organization->members()->attach($member, ['role' => $role]);
            $this->assertTrue($organization->permits($member, 'view'));
            $this->assertSame(in_array($role, ['admin', 'developer'], true), $organization->permits($member, 'deploy'));
            $this->assertSame($role === 'admin', $organization->permits($member, 'manage'));
        }

        $this->assertTrue($organization->permits($owner, 'manage'));
    }

    public function test_invitation_expiry_and_acceptance_are_enforced(): void
    {
        $owner = User::factory()->create();
        $organization = app(PersonalOrganization::class)->ensure($owner);
        $invitation = OrganizationInvitation::query()->create([
            'organization_id' => $organization->id,
            'invited_by' => $owner->id,
            'email' => 'member@example.com',
            'role' => 'developer',
            'token_hash' => hash('sha256', 'secret'),
            'expires_at' => now()->addDay(),
        ]);

        $this->assertTrue($invitation->isUsable());
        $invitation->update(['accepted_at' => now()]);
        $this->assertFalse($invitation->fresh()->isUsable());
    }
}
