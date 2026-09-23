<?php

namespace Tests\Feature;

use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeployerOrganizationContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_destination_uses_an_authorized_organization_for_this_request_only(): void
    {
        $user = User::factory()->create();
        $savedOrganizationId = (int) $user->current_organization_id;
        $organization = $this->organizationWithMember($user);
        $provider = $user->providers()->create([
            'name' => 'Mapped workspace provider',
            'provider' => 'github',
            'token' => 'context-provider-token',
            'description' => 'Organization context fixture',
        ]);
        $provider->forceFill(['organization_id' => $organization->getKey()])->save();
        $server = $organization->servers()->create([
            'user_id' => $user->getKey(),
            'provider_id' => $provider->getKey(),
            'name' => 'Mapped workspace server',
        ]);

        $this->actingAs($user)
            ->get(route('servers.show', [
                'server' => $server,
                'organization_id' => $organization->getKey(),
            ]))
            ->assertSuccessful();

        $this->assertSame($savedOrganizationId, (int) $user->fresh()->current_organization_id);
    }

    public function test_search_destination_cannot_select_an_organization_without_membership(): void
    {
        $user = User::factory()->create();
        $organization = $this->organizationWithMember(User::factory()->create());
        $server = $organization->servers()->create([
            'user_id' => $organization->owner_id,
            'name' => 'Private workspace server',
        ]);

        $this->actingAs($user)
            ->get(route('servers.show', [
                'server' => $server,
                'organization_id' => $organization->getKey(),
            ]))
            ->assertNotFound();
    }

    private function organizationWithMember(User $member): Organization
    {
        $owner = User::factory()->create();
        $organization = $owner->currentOrganization;
        $organization->members()->attach($member->getKey(), ['role' => 'viewer']);

        return $organization;
    }
}
