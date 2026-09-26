<?php

namespace Tests\Feature;

use App\Modules\Deployer\Models\OrganizationInvitation;
use App\Modules\Deployer\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DeployerOrganizationDataExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.products.deployer.auth_authority' => 'legacy']);
    }

    public function test_workspace_owner_can_stream_workspace_records_without_secrets_or_foreign_tenant_data(): void
    {
        $owner = User::factory()->create();
        $organization = $owner->currentOrganization;
        $project = $organization->projects()->create([
            'created_by' => $owner->getKey(),
            'name' => 'Production API',
            'slug' => 'production-api',
            'description' => 'Primary API project',
        ]);
        $environment = $project->environments()->create([
            'name' => 'Production',
            'slug' => 'production',
            'type' => 'production',
            'branch' => 'main',
        ]);
        $environment->variables()->create([
            'updated_by' => $owner->getKey(),
            'key' => 'DATABASE_URL',
            'value' => 'never-export-this-environment-secret',
            'is_secret' => true,
        ]);
        $invitationToken = hash('sha256', 'never-export-this-invitation-token');
        OrganizationInvitation::query()->create([
            'organization_id' => $organization->getKey(),
            'invited_by' => $owner->getKey(),
            'email' => 'pending@example.test',
            'role' => 'viewer',
            'token_hash' => $invitationToken,
            'expires_at' => now()->addDays(2),
        ]);

        $otherOwner = User::factory()->create();
        $otherProject = $otherOwner->currentOrganization->projects()->create([
            'created_by' => $otherOwner->getKey(),
            'name' => 'Foreign workspace project marker',
            'slug' => 'foreign-project',
        ]);
        $otherProject->environments()->create([
            'name' => 'Foreign production',
            'slug' => 'foreign-production',
            'type' => 'production',
            'branch' => 'main',
        ]);

        $response = $this->actingAs($owner)->get(route('organizations.data.export'));
        $response->assertOk()
            ->assertHeader('cache-control', 'private, no-store')
            ->assertHeader('content-type', 'application/x-ndjson; charset=UTF-8')
            ->assertHeader('x-content-type-options', 'nosniff');

        $content = $response->streamedContent();
        $records = collect(explode("\n", trim($content)))
            ->map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR));
        $export = $records->first();

        $this->assertSame('buildpusher-deployer-workspace-export', $export['data']['format']);
        $this->assertTrue($export['data']['privacy']['environment_variable_values_and_process_or_task_commands_are_excluded']);
        $this->assertTrue($records->contains(fn (array $record): bool => $record['type'] === 'workspace' && $record['data']['id'] === $organization->getKey()));
        $this->assertTrue($records->contains(fn (array $record): bool => $record['type'] === 'project' && $record['data']['name'] === 'Production API'));
        $this->assertTrue($records->contains(fn (array $record): bool => $record['type'] === 'environment' && $record['data']['id'] === $environment->getKey()));
        $this->assertTrue($records->contains(fn (array $record): bool => $record['type'] === 'environment_variable_metadata' && $record['data']['key'] === 'DATABASE_URL'));
        $this->assertStringNotContainsString('never-export-this-environment-secret', $content);
        $this->assertStringNotContainsString($invitationToken, $content);
        $this->assertStringNotContainsString('Foreign workspace project marker', $content);
    }

    public function test_workspace_data_screen_and_export_are_limited_to_owners_and_administrators(): void
    {
        $owner = User::factory()->create();
        $organization = $owner->currentOrganization;
        $admin = User::factory()->create();
        $viewer = User::factory()->create();
        $organization->members()->attach([
            $admin->getKey() => ['role' => 'admin'],
            $viewer->getKey() => ['role' => 'viewer'],
        ]);

        $this->actingAs($owner)
            ->get(route('organizations.data'))
            ->assertOk()
            ->assertSee('Download workspace export');
        $this->actingAs($admin)
            ->get(route('organizations.data', ['organization_id' => $organization->getKey()]))
            ->assertOk();
        $this->actingAs($viewer)
            ->get(route('organizations.data', ['organization_id' => $organization->getKey()]))
            ->assertForbidden();
        $this->actingAs($viewer)
            ->get(route('organizations.data.export', ['organization_id' => $organization->getKey()]))
            ->assertForbidden();
    }

    public function test_workspace_export_requires_authentication(): void
    {
        $this->get(route('organizations.data.export'))->assertRedirect(route('login'));
    }
}
