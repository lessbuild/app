<?php

namespace Tests\Feature\Core;

use App\Core\Contracts\ProductPlanResolver;
use App\Core\Contracts\ProjectResourceDestinationProvider;
use App\Core\Data\Billing\ProductPlanResolution;
use App\Core\Data\Projects\ProjectResourceDestination;
use App\Core\Data\Projects\ProjectResourceDestinationState;
use App\Core\Enums\ProductKey;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\Workspace;
use App\Core\Services\ProjectResourceDestinationRegistry;
use App\Core\Services\ProjectResourceDestinations;
use App\Core\Services\Projects\ExportProjectHandoverManifest;
use App\Core\Services\WorkspaceProjectAccess;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProjectHandoverTest extends TestCase
{
    private PlatformUser $user;

    private string $workspaceId;

    private string $membershipId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();
        $userId = (string) Str::ulid();
        $this->workspaceId = (string) Str::ulid();
        $this->membershipId = (string) Str::ulid();

        DB::connection('core')->table('users')->insert([
            'id' => $userId,
            'name' => 'Casey Owner',
            'email' => 'casey@example.test',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('workspaces')->insert([
            'id' => $this->workspaceId,
            'owner_user_id' => $userId,
            'name' => 'Northstar Studio',
            'slug' => 'northstar-studio',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('workspace_memberships')->insert([
            'id' => $this->membershipId,
            'workspace_id' => $this->workspaceId,
            'user_id' => $userId,
            'role' => 'owner',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('workspace_product_access')->insert([
            'id' => (string) Str::ulid(),
            'membership_id' => $this->membershipId,
            'product' => 'deployer',
            'role' => 'owner',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->user = PlatformUser::query()->findOrFail($userId);

        app()->instance(ProductPlanResolver::class, new class implements ProductPlanResolver
        {
            public function resolve(string $workspaceId, ProductKey $product): ProductPlanResolution
            {
                return new ProductPlanResolution(
                    product: $product,
                    workspaceId: $workspaceId,
                    available: true,
                    planKey: $product->value.'-team',
                    entitlements: ['*'],
                    limits: ['projects' => 25],
                );
            }
        });

        app(ProjectResourceDestinationRegistry::class)->register('deployer', new class implements ProjectResourceDestinationProvider
        {
            public function destinations(PlatformUser $user, Collection $resources): array
            {
                return $resources->mapWithKeys(fn ($resource): array => [
                    (string) $resource->getKey() => new ProjectResourceDestination(ProjectResourceDestinationState::Available),
                ])->all();
            }
        });

        Auth::forgetGuards();
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        foreach ([
            'project_connections',
            'project_resources',
            'project_environments',
            'project_products',
            'projects',
            'workspace_product_access',
            'workspace_memberships',
            'workspaces',
            'users',
        ] as $table) {
            Schema::connection('core')->dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_owner_can_export_a_private_manifest_without_secrets_or_product_credentials(): void
    {
        $projectId = $this->createProject('current-project', 'Current project');
        DB::connection('core')->table('project_products')->insert([
            'id' => (string) Str::ulid(),
            'project_id' => $projectId,
            'product' => 'deployer',
            'status' => 'active',
            'metadata' => json_encode(['secret' => 'do-not-export']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('project_resources')->insert([
            'id' => (string) Str::ulid(),
            'project_id' => $projectId,
            'product' => 'deployer',
            'resource_type' => 'project',
            'resource_id' => '804',
            'resource_public_id' => 'analytics-collection-bearer',
            'name' => 'Checkout',
            'status' => 'active',
            'metadata' => json_encode(['token' => 'do-not-export']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user, 'platform')
            ->get(route('core.projects.handover.export', [$this->workspaceId, $projectId]));

        $response->assertOk()
            ->assertHeader('cache-control')
            ->assertHeader('x-content-type-options', 'nosniff');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('cache-control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('cache-control'));

        $manifest = app(ExportProjectHandoverManifest::class)->handle(
            $this->user,
            Workspace::query()->findOrFail($this->workspaceId),
            Project::query()->findOrFail($projectId),
            app(WorkspaceProjectAccess::class),
            app(ProjectResourceDestinations::class),
        );
        $json = json_encode($manifest, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('do-not-export', $json);
        $this->assertStringNotContainsString('analytics-collection-bearer', $json);
        $this->assertArrayNotHasKey('public_id', $manifest['resources'][0]);
        $this->assertArrayNotHasKey('description', $manifest['project']);
        $this->assertArrayNotHasKey('metadata', $manifest['project']);
        $this->assertFalse($manifest['security']['secrets_included']);
        $this->assertFalse($manifest['security']['authentication_credentials_included']);
    }

    public function test_dry_run_checks_destination_and_does_not_write_projects_or_resources(): void
    {
        $manifest = $this->manifest();
        $before = [
            'projects' => DB::connection('core')->table('projects')->count(),
            'project_resources' => DB::connection('core')->table('project_resources')->count(),
            'project_connections' => DB::connection('core')->table('project_connections')->count(),
        ];

        $response = $this->actingAs($this->user, 'platform')->post(
            route('core.projects.handover.validate', $this->workspaceId),
            ['manifest' => UploadedFile::fake()->createWithContent('project.json', json_encode($manifest, JSON_THROW_ON_ERROR))],
        );

        $response->assertOk()
            ->assertSeeText('Ready')
            ->assertSeeText('Access available')
            ->assertSeeText('Available')
            ->assertSeeText('Subscriptions remain workspace-owned');
        $this->assertSame($before, [
            'projects' => DB::connection('core')->table('projects')->count(),
            'project_resources' => DB::connection('core')->table('project_resources')->count(),
            'project_connections' => DB::connection('core')->table('project_connections')->count(),
        ]);
    }

    public function test_sensitive_fields_and_unresolved_references_are_rejected_without_echoing_upload_content(): void
    {
        $manifest = $this->manifest();
        $manifest['resources'][0]['api_key'] = 'secret-marker-should-never-render';

        $response = $this->actingAs($this->user, 'platform')->post(
            route('core.projects.handover.validate', $this->workspaceId),
            ['manifest' => UploadedFile::fake()->createWithContent('unsafe.json', json_encode($manifest, JSON_THROW_ON_ERROR))],
        );
        $response->assertOk()
            ->assertSeeText('Blocked')
            ->assertSeeText('contains unsafe fields')
            ->assertDontSee('secret-marker-should-never-render')
            ->assertDontSee('9001');

        $manifest = $this->manifest();
        $danglingReference = 'environment_'.(string) Str::ulid();
        $manifest['resources'][0]['environment_ref'] = $danglingReference;
        $response = $this->actingAs($this->user, 'platform')->post(
            route('core.projects.handover.validate', $this->workspaceId),
            ['manifest' => UploadedFile::fake()->createWithContent('unresolved.json', json_encode($manifest, JSON_THROW_ON_ERROR))],
        );
        $response->assertOk()->assertSeeText('Blocked')->assertDontSee($danglingReference);
    }

    public function test_members_without_workspace_management_cannot_open_the_handover_tool(): void
    {
        DB::connection('core')->table('workspace_memberships')->where('id', $this->membershipId)->update(['role' => 'member']);

        $this->actingAs($this->user, 'platform')
            ->get(route('core.projects.handover.form', $this->workspaceId))
            ->assertForbidden();
    }

    public function test_existing_resource_mapping_is_reported_without_exposing_the_other_workspace(): void
    {
        $otherWorkspaceId = (string) Str::ulid();
        DB::connection('core')->table('workspaces')->insert([
            'id' => $otherWorkspaceId,
            'owner_user_id' => $this->user->getKey(),
            'name' => 'Private workspace',
            'slug' => 'private-workspace',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherProjectId = (string) Str::ulid();
        DB::connection('core')->table('projects')->insert([
            'id' => $otherProjectId,
            'workspace_id' => $otherWorkspaceId,
            'created_by_user_id' => $this->user->getKey(),
            'name' => 'Private project',
            'slug' => 'private-project',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('project_resources')->insert([
            'id' => (string) Str::ulid(),
            'project_id' => $otherProjectId,
            'product' => 'deployer',
            'resource_type' => 'environment',
            'resource_id' => '9001',
            'name' => 'Private resource',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user, 'platform')->post(
            route('core.projects.handover.validate', $this->workspaceId),
            ['manifest' => UploadedFile::fake()->createWithContent('project.json', json_encode($this->manifest(), JSON_THROW_ON_ERROR))],
        );

        $response->assertOk()
            ->assertSeeText('Review')
            ->assertSeeText('mapped to another Buildpusher project')
            ->assertDontSeeText('Private workspace')
            ->assertDontSeeText('Private project');
    }

    public function test_non_billing_workspace_admin_does_not_see_plan_limits(): void
    {
        DB::connection('core')->table('workspace_memberships')->where('id', $this->membershipId)->update(['role' => 'admin']);

        $response = $this->actingAs($this->user, 'platform')->post(
            route('core.projects.handover.validate', $this->workspaceId),
            ['manifest' => UploadedFile::fake()->createWithContent('project.json', json_encode($this->manifest(), JSON_THROW_ON_ERROR))],
        );

        $response->assertOk()
            ->assertSeeText('Detailed plan status is limited to billing managers.')
            ->assertDontSeeText('25');
    }

    public function test_missing_destination_product_access_blocks_resource_validation(): void
    {
        DB::connection('core')->table('workspace_product_access')->where('membership_id', $this->membershipId)->delete();

        $response = $this->actingAs($this->user, 'platform')->post(
            route('core.projects.handover.validate', $this->workspaceId),
            ['manifest' => UploadedFile::fake()->createWithContent('project.json', json_encode($this->manifest(), JSON_THROW_ON_ERROR))],
        );

        $response->assertOk()
            ->assertSeeText('Blocked')
            ->assertSeeText('Access required')
            ->assertSeeText('needs current access to Deployer');
    }

    public function test_unavailable_product_provider_is_reported_as_a_blocker(): void
    {
        app()->instance(ProjectResourceDestinationRegistry::class, new ProjectResourceDestinationRegistry);

        $response = $this->actingAs($this->user, 'platform')->post(
            route('core.projects.handover.validate', $this->workspaceId),
            ['manifest' => UploadedFile::fake()->createWithContent('project.json', json_encode($this->manifest(), JSON_THROW_ON_ERROR))],
        );

        $response->assertOk()
            ->assertSeeText('Blocked')
            ->assertSeeText('resource provider is not available');
    }

    public function test_destination_connection_entitlements_are_checked_without_creating_connections(): void
    {
        DB::connection('core')->table('workspace_product_access')->insert([
            'id' => (string) Str::ulid(),
            'membership_id' => $this->membershipId,
            'product' => 'monitor',
            'role' => 'owner',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app()->instance(ProductPlanResolver::class, new class implements ProductPlanResolver
        {
            public function resolve(string $workspaceId, ProductKey $product): ProductPlanResolution
            {
                return new ProductPlanResolution(
                    product: $product,
                    workspaceId: $workspaceId,
                    available: true,
                    planKey: $product->value.'-team',
                    entitlements: ['*'],
                    limits: $product === ProductKey::Monitor ? ['deployment_context_minutes' => 0] : [],
                );
            }
        });
        app(ProjectResourceDestinationRegistry::class)->register('monitor', new class implements ProjectResourceDestinationProvider
        {
            public function destinations(PlatformUser $user, Collection $resources): array
            {
                return $resources->mapWithKeys(fn ($resource): array => [
                    (string) $resource->getKey() => new ProjectResourceDestination(ProjectResourceDestinationState::Available),
                ])->all();
            }
        });

        $manifest = $this->manifest();
        $monitorResourceRef = 'resource_'.(string) Str::ulid();
        $deployerResourceRef = $manifest['resources'][0]['ref'];
        $environmentRef = $manifest['environments'][0]['ref'];
        $manifest['products'][] = 'monitor';
        $manifest['resources'][] = [
            'ref' => $monitorResourceRef,
            'product' => 'monitor',
            'resource_type' => 'environment',
            'local_resource_id' => 'monitor-9001',
            'name' => 'Production checks',
            'environment_ref' => $environmentRef,
        ];
        $manifest['connections'][] = [
            'ref' => 'connection_'.(string) Str::ulid(),
            'source_resource_ref' => $deployerResourceRef,
            'target_resource_ref' => $monitorResourceRef,
            'source_environment_ref' => $environmentRef,
            'target_environment_ref' => $environmentRef,
            'capabilities' => ['deployment_context'],
        ];
        $manifest['configuration_references'][] = [
            'product' => 'monitor',
            'resource_ref' => $monitorResourceRef,
            'kind' => 'monitoring_configuration',
            'values_included' => false,
        ];
        $manifest['setup_instructions']['monitor'] = ['Confirm application ownership, checks, and alert routes.'];
        $before = DB::connection('core')->table('project_connections')->count();

        $response = $this->actingAs($this->user, 'platform')->post(
            route('core.projects.handover.validate', $this->workspaceId),
            ['manifest' => UploadedFile::fake()->createWithContent('project.json', json_encode($manifest, JSON_THROW_ON_ERROR))],
        );

        $response->assertOk()
            ->assertSeeText('Blocked')
            ->assertSeeText('plan or product access does not allow the Deployment context connection');
        $this->assertSame($before, DB::connection('core')->table('project_connections')->count());
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $sourceProjectId = (string) Str::ulid();
        $environmentRef = 'environment_'.(string) Str::ulid();
        $resourceRef = 'resource_'.(string) Str::ulid();

        return [
            'schema' => 'buildpusher.project-handover',
            'version' => 1,
            'exported_at' => now()->toIso8601String(),
            'source' => [
                'workspace_id' => (string) Str::ulid(),
                'workspace_slug' => 'source-workspace',
                'project_id' => $sourceProjectId,
                'project_slug' => 'incoming-project',
            ],
            'ownership' => [
                'workspace_owner_user_id' => (string) Str::ulid(),
                'project_creator_user_id' => null,
            ],
            'project' => ['name' => 'Incoming project', 'slug' => 'incoming-project'],
            'products' => ['deployer'],
            'environments' => [[
                'ref' => $environmentRef,
                'name' => 'Production',
                'slug' => 'production',
                'type' => 'production',
            ]],
            'resources' => [[
                'ref' => $resourceRef,
                'product' => 'deployer',
                'resource_type' => 'environment',
                'local_resource_id' => '9001',
                'name' => 'Production app',
                'environment_ref' => $environmentRef,
            ]],
            'connections' => [],
            'configuration_references' => [[
                'product' => 'deployer',
                'resource_ref' => $resourceRef,
                'kind' => 'deployment_configuration',
                'values_included' => false,
            ]],
            'setup_instructions' => ['deployer' => ['Reconnect the authorized repository and deployment target.']],
            'omitted' => ['resource_mappings' => 0, 'connection_mappings' => 0, 'reason' => 'Some mappings may be omitted.'],
            'security' => [
                'secrets_included' => false,
                'authentication_credentials_included' => false,
                'environment_values_included' => false,
                'subscriptions_included' => false,
                'arbitrary_metadata_included' => false,
                'is_backup' => false,
                'note' => 'Reconnect credentials separately.',
            ],
        ];
    }

    private function createProject(string $slug, string $name): string
    {
        $projectId = (string) Str::ulid();
        DB::connection('core')->table('projects')->insert([
            'id' => $projectId,
            'workspace_id' => $this->workspaceId,
            'created_by_user_id' => $this->user->getKey(),
            'name' => $name,
            'slug' => $slug,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $projectId;
    }

    private function createTables(): void
    {
        $core = Schema::connection('core');
        $core->create('users', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('status')->default('active');
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->json('preferences')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        $core->create('workspaces', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('owner_user_id');
            $table->string('name');
            $table->string('slug');
            $table->string('status');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        $core->create('workspace_memberships', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('user_id');
            $table->string('role');
            $table->string('status');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        $core->create('workspace_product_access', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('membership_id');
            $table->string('product');
            $table->string('role');
            $table->string('status');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        $core->create('projects', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('created_by_user_id')->nullable();
            $table->string('name');
            $table->string('slug');
            $table->string('status');
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        $core->create('project_environments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('project_id');
            $table->string('name');
            $table->string('slug');
            $table->string('environment_type');
            $table->string('status');
            $table->timestamps();
        });
        $core->create('project_products', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('project_id');
            $table->string('product');
            $table->string('status');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        $core->create('project_resources', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('project_id');
            $table->ulid('environment_id')->nullable();
            $table->string('product');
            $table->string('resource_type');
            $table->string('resource_id');
            $table->string('resource_public_id')->nullable();
            $table->string('name')->nullable();
            $table->string('status');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        $core->create('project_connections', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('project_id');
            $table->ulid('source_resource_id');
            $table->ulid('target_resource_id');
            $table->ulid('source_environment_id')->nullable();
            $table->ulid('target_environment_id')->nullable();
            $table->json('capabilities');
            $table->string('status');
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamps();
        });
    }
}
