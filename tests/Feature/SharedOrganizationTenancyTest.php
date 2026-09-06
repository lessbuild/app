<?php

namespace Tests\Feature;

use App\Models\Build;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class SharedOrganizationTenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_sees_workspace_resources_but_outsider_does_not(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $outsider = User::factory()->create();
        $organization = $owner->currentOrganization;
        $organization->members()->attach($member, ['role' => 'viewer']);
        $member->update(['current_organization_id' => $organization->id]);
        $provider = $owner->providers()->create(['name' => 'Cloud', 'provider' => 'digitalocean', 'description' => 'Cloud', 'token' => 'token']);
        $server = $owner->servers()->create(['name' => 'shared-production', 'provider_id' => $provider->id]);

        $this->assertSame($organization->id, $server->organization_id);
        $this->assertTrue($server->organization->permits($member, 'view'));
        $this->assertTrue(Gate::forUser($member)->allows('view', $server));
        $this->actingAs($member)->get(route('servers.index'))->assertOk()->assertSee('shared-production');
        $response = $this->actingAs($member)->get(route('servers.show', $server));
        $response->assertOk();
        $this->actingAs($outsider)->get(route('servers.show', $server))->assertForbidden();
        $this->actingAs($outsider)->get(route('servers.index'))->assertOk()->assertDontSee('shared-production');
    }

    public function test_viewer_cannot_modify_shared_resource_but_developer_can(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $developer = User::factory()->create();
        $organization = $owner->currentOrganization;
        $organization->members()->attach($viewer, ['role' => 'viewer']);
        $organization->members()->attach($developer, ['role' => 'developer']);
        $viewer->update(['current_organization_id' => $organization->id]);
        $developer->update(['current_organization_id' => $organization->id]);
        $server = $owner->servers()->create(['name' => 'production']);

        $payload = ['display_name' => 'Primary'];
        $this->actingAs($viewer)->patch(route('servers.update', $server), $payload)->assertForbidden();
        $this->actingAs($developer)->patch(route('servers.update', $server), $payload)->assertRedirect(route('servers.show', $server));
        $this->assertSame('Primary', $server->fresh()->display_name);
    }

    public function test_workspace_creation_assigns_resource_creator_and_tenant(): void
    {
        $owner = User::factory()->create();
        $developer = User::factory()->create();
        $organization = $owner->currentOrganization;
        $organization->members()->attach($developer, ['role' => 'developer']);
        $developer->update(['current_organization_id' => $organization->id]);

        $this->actingAs($developer);
        $server = $developer->workspaceServers()->create(['name' => 'worker']);

        $this->assertSame($developer->id, $server->user_id);
        $this->assertSame($organization->id, $server->organization_id);
        $this->assertTrue($organization->servers()->whereKey($server->id)->exists());
    }

    public function test_members_cannot_access_a_workspace_while_another_workspace_is_active(): void
    {
        $user = User::factory()->create();
        $otherOwner = User::factory()->create();
        $other = $otherOwner->currentOrganization;
        $other->members()->attach($user, ['role' => 'developer']);

        $provider = $otherOwner->providers()->create([
            'name' => 'Inactive workspace provider', 'provider' => Provider::TYPE_DIGITALOCEAN,
            'description' => 'Cloud', 'token' => 'secret',
        ]);
        $server = $otherOwner->servers()->create([
            'provider_id' => $provider->id, 'name' => 'inactive-workspace-server',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $website = $otherOwner->websites()->create([
            'server_id' => $server->id, 'name' => 'Inactive site',
            'description' => 'Other workspace', 'environment' => '',
            'url' => 'inactive.example.com', 'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
        $repository = $otherOwner->repositories()->create([
            'provider_id' => $provider->id, 'website_id' => $website->id,
            'name' => 'Inactive repository', 'url' => 'github.com/example/inactive.git',
            'branch' => 'main', 'description' => 'Other workspace',
        ]);
        $project = $other->projects()->create([
            'created_by' => $otherOwner->id, 'name' => 'Inactive project', 'slug' => 'inactive-project',
        ]);
        $build = $repository->builds()->create([
            'status' => Build::STATUS_QUEUED, 'trigger_source' => Build::TRIGGER_MANUAL,
            'requested_by' => $otherOwner->id,
        ]);

        foreach ([
            route('providers.show', $provider), route('servers.show', $server),
            route('websites.show', $website), route('repositories.show', $repository),
            route('projects.show', $project), route('builds.show', $build),
        ] as $url) {
            $this->actingAs($user)->get($url)->assertForbidden();
        }

        foreach ([
            ['update', $provider], ['update', $server], ['update', $website],
            ['update', $repository], ['update', $project], ['cancel', $build],
        ] as [$ability, $resource]) {
            $this->assertFalse(Gate::forUser($user)->allows($ability, $resource));
        }

        $this->actingAs($user)->get(route('servers.index'))
            ->assertOk()
            ->assertDontSee('inactive-workspace-server');

        $user->update(['current_organization_id' => $other->id]);
        $this->actingAs($user)->get(route('servers.show', $server))->assertOk();
    }
}
