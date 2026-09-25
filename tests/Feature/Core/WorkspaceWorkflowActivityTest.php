<?php

namespace Tests\Feature\Core;

use App\Core\Contracts\ProjectResourceDestinationProvider;
use App\Core\Contracts\WorkspaceCredentialProvider;
use App\Core\Contracts\WorkspaceWebhookDeliveryProvider;
use App\Core\Data\Credentials\WorkspaceCredential;
use App\Core\Data\Credentials\WorkspaceCredentialSnapshot;
use App\Core\Data\Projects\ProjectResourceDestination;
use App\Core\Data\Projects\ProjectResourceDestinationState;
use App\Core\Data\Projects\WorkspaceWebhookDelivery;
use App\Core\Data\Projects\WorkspaceWebhookDeliverySnapshot;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project as CoreProject;
use App\Core\Models\ProjectResource;
use App\Core\Models\Workspace;
use App\Core\Services\ProjectResourceDestinationRegistry;
use App\Core\Services\WorkspaceActivityProviderRegistry;
use App\Core\Services\WorkspaceCredentialProviderRegistry;
use App\Core\Services\WorkspaceProjectAccess;
use App\Core\Services\WorkspaceWebhookDeliveryProviderRegistry;
use App\Modules\Deployer\Services\Core\DeployerProjectLink;
use App\Modules\Monitor\Services\Core\MonitorWorkspaceActivityProvider;
use App\Modules\Monitor\Services\Core\MonitorWorkspaceCredentialProvider;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WorkspaceWorkflowActivityTest extends TestCase
{
    private string $userId;

    private string $workspaceId;

    private string $projectId;

    private string $membershipId;

    private object $destinationStates;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->createTables();
        $this->createDeployerTables();

        $this->userId = (string) Str::ulid();
        $this->workspaceId = (string) Str::ulid();
        $this->projectId = (string) Str::ulid();
        $this->membershipId = (string) Str::ulid();
        $this->destinationStates = (object) [
            'deployer' => ProjectResourceDestinationState::Available,
            'monitor' => ProjectResourceDestinationState::Available,
        ];

        DB::connection('core')->table('users')->insert([
            'id' => $this->userId,
            'name' => 'Taylor Owner',
            'email' => 'taylor@example.test',
            'email_normalized' => 'taylor@example.test',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('workspaces')->insert([
            'id' => $this->workspaceId,
            'owner_user_id' => $this->userId,
            'name' => 'Northstar Studio',
            'slug' => 'northstar-studio',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('workspace_memberships')->insert([
            'id' => $this->membershipId,
            'workspace_id' => $this->workspaceId,
            'user_id' => $this->userId,
            'role' => 'owner',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['deployer', 'monitor'] as $product) {
            DB::connection('core')->table('workspace_product_access')->insert([
                'id' => (string) Str::ulid(),
                'membership_id' => $this->membershipId,
                'product' => $product,
                'role' => 'owner',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::connection('core')->table('projects')->insert([
            'id' => $this->projectId,
            'workspace_id' => $this->workspaceId,
            'created_by_user_id' => $this->userId,
            'name' => 'Checkout app',
            'slug' => 'checkout-app',
            'status' => 'active',
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('project_memberships')->insert([
            'id' => (string) Str::ulid(),
            'project_id' => $this->projectId,
            'user_id' => $this->userId,
            'role' => 'owner',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['deployer', 'monitor'] as $product) {
            DB::connection('core')->table('project_products')->insert([
                'id' => (string) Str::ulid(),
                'project_id' => $this->projectId,
                'product' => $product,
                'status' => 'active',
                'metadata' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $sourceResourceId = (string) Str::ulid();
        $targetResourceId = (string) Str::ulid();
        foreach ([
            [$sourceResourceId, 'deployer', 'Production environment'],
            [$targetResourceId, 'monitor', 'Production checks'],
        ] as [$resourceId, $product, $name]) {
            DB::connection('core')->table('project_resources')->insert([
                'id' => $resourceId,
                'project_id' => $this->projectId,
                'product' => $product,
                'resource_type' => 'environment',
                'resource_id' => 'local-'.$product.'-production',
                'name' => $name,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $connectionId = (string) Str::ulid();
        DB::connection('core')->table('project_connections')->insert([
            'id' => $connectionId,
            'project_id' => $this->projectId,
            'source_resource_id' => $sourceResourceId,
            'target_resource_id' => $targetResourceId,
            'capabilities' => json_encode(['deployment_context']),
            'status' => 'failed',
            'last_error_code' => 'provider_secret_must_not_render',
            'last_error_at' => now()->subMinute(),
            'created_at' => now()->subDay(),
            'updated_at' => now()->subMinute(),
        ]);
        DB::connection('core')->table('project_connection_deliveries')->insert([
            'id' => (string) Str::ulid(),
            'project_connection_id' => $connectionId,
            'source_event_id' => (string) Str::ulid(),
            'event_type' => 'deployer.deployment_succeeded',
            'event_version' => 1,
            'payload' => json_encode(['version' => 'v12', 'deployed_at' => now()->subMinutes(5)->toIso8601String()]),
            'status' => 'failed',
            'attempts' => 3,
            'available_at' => null,
            'last_attempted_at' => now()->subMinute(),
            'delivered_at' => null,
            'last_error_code' => 'provider_secret_must_not_render',
            'last_error_at' => now()->subMinute(),
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinute(),
        ]);

        $registry = app(ProjectResourceDestinationRegistry::class);
        foreach (['deployer', 'monitor'] as $product) {
            $registry->register($product, new class($product, $this->destinationStates) implements ProjectResourceDestinationProvider
            {
                public function __construct(private readonly string $product, private readonly object $states) {}

                public function destinations(PlatformUser $user, Collection $resources): array
                {
                    return $resources->mapWithKeys(fn ($resource): array => [
                        (string) $resource->getKey() => new ProjectResourceDestination($this->states->{$this->product}),
                    ])->all();
                }
            });
        }

        Auth::forgetGuards();
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();

        foreach ([
            'project_connection_deliveries',
            'project_connections',
            'project_resources',
            'project_environments',
            'project_products',
            'project_memberships',
            'projects',
            'workspace_product_access',
            'workspace_memberships',
            'workspaces',
            'legacy_identity_maps',
            'users',
        ] as $table) {
            Schema::connection('core')->dropIfExists($table);
        }

        foreach ([
            'database_clones',
            'environment_resources',
            'scheduled_task_runs',
            'scheduled_tasks',
            'server_command_executions',
            'backup_restore_verifications',
            'backup_restores',
            'website_backups',
            'configuration_operations',
            'configuration_applications',
            'configuration_reviews',
            'preview_stack_cleanups',
            'preview_deployments',
            'repository_webhook_deliveries',
            'server_diagnostic_snapshots',
            'deployment_observations',
            'website_log_snapshots',
            'server_log_snapshots',
            'website_domains',
            'website_health_checks',
            'builds',
            'repositories',
            'websites',
            'servers',
            'environments',
            'projects',
            'organizations',
            'users',
        ] as $table) {
            Schema::connection('deployer')->dropIfExists($table);
        }

        foreach ([
            'alert_delivery_attempts',
            'alert_deliveries',
            'alert_destinations',
            'incidents',
            'alert_rules',
            'heartbeat_runs',
            'monitor_checks',
            'monitors',
            'ingest_tokens',
            'ingest_receipts',
            'environments',
            'applications',
            'user_workspace',
            'workspaces',
            'users',
        ] as $table) {
            Schema::connection('monitor')->dropIfExists($table);
        }

        parent::tearDown();
    }

    private function createTables(): void
    {
        Schema::connection('core')->create('users', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('email_normalized')->nullable();
            $table->string('status', 24)->default('active');
            $table->timestamps();
        });
        Schema::connection('core')->create('workspaces', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('owner_user_id', 26)->nullable();
            $table->string('name');
            $table->string('slug');
            $table->string('status', 24)->default('active');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('workspace_memberships', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('user_id', 26);
            $table->string('role', 24);
            $table->string('status', 24);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('workspace_product_access', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('membership_id', 26);
            $table->string('product', 24);
            $table->string('role', 24);
            $table->string('status', 24);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('legacy_identity_maps', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('source_product', 24);
            $table->string('source_entity', 100);
            $table->string('source_id', 191);
            $table->string('canonical_entity', 100)->nullable();
            $table->string('canonical_id', 191)->nullable();
            $table->string('status', 24)->default('pending');
            $table->timestamps();
        });
        Schema::connection('core')->create('projects', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('created_by_user_id', 26)->nullable();
            $table->string('name');
            $table->string('slug');
            $table->string('status', 24);
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('project_memberships', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('project_id', 26);
            $table->char('user_id', 26);
            $table->string('role', 24);
            $table->string('status', 24);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('project_products', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('project_id', 26);
            $table->string('product', 24);
            $table->string('status', 24);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('project_resources', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('project_id', 26);
            $table->char('environment_id', 26)->nullable();
            $table->string('product', 24);
            $table->string('resource_type', 100);
            $table->string('resource_id', 191);
            $table->string('name')->nullable();
            $table->string('status', 24);
            $table->timestamps();
        });
        Schema::connection('core')->create('project_environments', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('project_id', 26);
            $table->char('created_by_user_id', 26)->nullable();
            $table->string('name');
            $table->string('slug');
            $table->string('environment_type', 24)->default('production');
            $table->string('status', 24)->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('project_connections', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('project_id', 26);
            $table->char('source_resource_id', 26)->nullable();
            $table->char('target_resource_id', 26)->nullable();
            $table->char('source_environment_id', 26)->nullable();
            $table->char('target_environment_id', 26)->nullable();
            $table->json('capabilities')->nullable();
            $table->string('status', 24);
            $table->timestamp('last_succeeded_at')->nullable();
            $table->string('last_error_code', 120)->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamp('automation_paused_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('project_connection_deliveries', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('project_connection_id', 26);
            $table->string('source_event_id', 191);
            $table->string('event_type', 100);
            $table->unsignedSmallInteger('event_version');
            $table->json('payload');
            $table->string('status', 24);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->string('last_error_code', 120)->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamps();
        });
    }

    private function createDeployerTables(): void
    {
        Schema::connection('deployer')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('current_organization_id')->nullable();
        });
        Schema::connection('deployer')->create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('owner_id');
        });
        Schema::connection('deployer')->create('projects', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
        });
        Schema::connection('deployer')->create('environments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('server_id')->nullable();
            $table->unsignedBigInteger('website_id')->nullable();
            $table->string('name');
            $table->string('slug')->default('production');
            $table->string('type')->default('production');
        });
        Schema::connection('deployer')->create('servers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('provider_id')->nullable();
            $table->string('name')->nullable();
            $table->string('type')->default('app');
            $table->unsignedInteger('setup_stage')->default(0);
            $table->string('provisioning_status')->default('queued');
            $table->text('provisioning_error')->nullable();
            $table->string('public_ip')->nullable();
            $table->string('password')->nullable();
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('deployer')->create('websites', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('server_id')->nullable();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('name')->nullable();
            $table->unsignedInteger('setup_stage')->default(0);
            $table->string('provisioning_status')->default('queued');
            $table->text('provisioning_error')->nullable();
            $table->text('database_password')->nullable();
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::connection('deployer')->create('repositories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('website_id');
            $table->unsignedBigInteger('provider_id')->nullable();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('deployer')->create('builds', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('environment_id')->nullable();
            $table->string('status')->nullable();
            $table->unsignedSmallInteger('setup_stage')->default(0);
            $table->string('revision')->nullable();
            $table->string('release_name')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_workflow_activity_persists_steps_and_offers_only_the_failed_step_for_retry(): void
    {
        $user = PlatformUser::query()->findOrFail($this->userId);
        $response = $this->actingAs($user, 'platform')
            ->get(route('core.workspace.workflows', $this->workspaceId));

        $response
            ->assertOk()
            ->assertSeeText('Workflow activity')
            ->assertSeeText('Deployment v12 succeeded')
            ->assertSeeText('Deployment context recorded')
            ->assertSeeText('Project: Checkout app')
            ->assertSeeText('Retry this step')
            ->assertDontSeeText('provider_secret_must_not_render');
        $response->assertSee(route('core.projects.show', [$this->workspaceId, $this->projectId]));
    }

    public function test_workspace_delivery_history_aggregates_safe_product_owned_records_and_filters_them(): void
    {
        DB::connection('core')->table('workspace_product_access')
            ->where('membership_id', $this->membershipId)
            ->where('product', 'monitor')
            ->update(['revoked_at' => now()]);

        app(WorkspaceWebhookDeliveryProviderRegistry::class)->register('deployer', new class implements WorkspaceWebhookDeliveryProvider
        {
            public function recentWebhookDeliveriesForWorkspace(
                PlatformUser $user,
                Workspace $workspace,
                Collection $projects,
                int $limit,
            ): WorkspaceWebhookDeliverySnapshot {
                return new WorkspaceWebhookDeliverySnapshot(collect([
                    new WorkspaceWebhookDelivery(
                        key: 'deployer:webhook:fixture',
                        product: 'deployer',
                        productLabel: 'Buildpusher Deploy',
                        projectName: 'Checkout app',
                        title: 'Repository webhook',
                        status: 'unavailable',
                        statusLabel: 'Unavailable',
                        attemptCount: null,
                        recordedAt: CarbonImmutable::parse('2026-09-25 10:00:00 UTC'),
                        resultUrl: 'https://deployer.example.test/repositories/91?organization_id=50',
                    ),
                ]));
            }
        });

        $user = PlatformUser::query()->findOrFail($this->userId);
        $this->actingAs($user, 'platform')
            ->get(route('core.workspace.deliveries', $this->workspaceId))
            ->assertOk()
            ->assertSeeText('Webhook delivery history')
            ->assertSeeText('Repository webhook')
            ->assertSeeText('Unavailable')
            ->assertSeeText('Checkout app')
            ->assertSee('https://deployer.example.test/repositories/91?organization_id=50', false)
            ->assertDontSeeText('webhook-secret-must-not-render');

        $this->get(route('core.workspace.deliveries', ['workspace' => $this->workspaceId, 'status' => 'failed']))
            ->assertOk()
            ->assertSeeText('No webhook deliveries match these filters')
            ->assertDontSeeText('Repository webhook');
    }

    public function test_workspace_credential_inventory_aggregates_and_filters_redacted_product_summaries(): void
    {
        app(WorkspaceCredentialProviderRegistry::class)->register('deployer', new class implements WorkspaceCredentialProvider
        {
            public function credentialsForWorkspace(
                PlatformUser $user,
                Workspace $workspace,
                Collection $projects,
                int $limit,
            ): WorkspaceCredentialSnapshot {
                return new WorkspaceCredentialSnapshot(collect([
                    new WorkspaceCredential(
                        key: 'deployer:token:1',
                        product: 'deployer',
                        productLabel: 'Buildpusher Deploy',
                        type: 'Deployer API token',
                        name: 'Production deploy token',
                        scope: 'Workspace: Northstar Studio',
                        status: 'active',
                        statusLabel: 'Active',
                        prefix: null,
                        createdAt: CarbonImmutable::parse('2026-09-25 10:00:00 UTC'),
                        lastUsedAt: null,
                        expiresAt: null,
                        manageUrl: 'https://deployer.example.test/automation',
                    ),
                ]));
            }
        });
        app(WorkspaceCredentialProviderRegistry::class)->register('monitor', new class implements WorkspaceCredentialProvider
        {
            public function credentialsForWorkspace(
                PlatformUser $user,
                Workspace $workspace,
                Collection $projects,
                int $limit,
            ): WorkspaceCredentialSnapshot {
                return new WorkspaceCredentialSnapshot(collect([
                    new WorkspaceCredential(
                        key: 'monitor:ingest-token:1',
                        product: 'monitor',
                        productLabel: 'Buildpusher Monitor',
                        type: 'Monitor ingestion token',
                        name: 'Production collector token',
                        scope: 'Checkout app · Production',
                        status: 'active',
                        statusLabel: 'Active',
                        prefix: 'bcn_live',
                        createdAt: CarbonImmutable::parse('2026-09-25 11:00:00 UTC'),
                        lastUsedAt: null,
                        expiresAt: null,
                        manageUrl: 'https://monitor.example.test/environments/92',
                    ),
                    new WorkspaceCredential(
                        key: 'monitor:ingest-token:2',
                        product: 'monitor',
                        productLabel: 'Buildpusher Monitor',
                        type: 'Monitor ingestion token',
                        name: 'Revoked collector token',
                        scope: 'Checkout app · Production',
                        status: 'revoked',
                        statusLabel: 'Revoked',
                        prefix: 'bcn_old',
                        createdAt: CarbonImmutable::parse('2026-09-24 11:00:00 UTC'),
                        lastUsedAt: null,
                        expiresAt: null,
                        manageUrl: 'https://monitor.example.test/environments/92',
                    ),
                ]));
            }
        });

        $user = PlatformUser::query()->findOrFail($this->userId);
        $this->actingAs($user, 'platform')
            ->get(route('core.workspace.credentials', [
                'workspace' => $this->workspaceId,
                'product' => 'monitor',
                'status' => 'active',
            ]))
            ->assertOk()
            ->assertSeeText('Credential inventory')
            ->assertSeeText('Production collector token')
            ->assertSeeText('bcn_live')
            ->assertDontSeeText('Production deploy token')
            ->assertDontSeeText('Revoked collector token')
            ->assertSeeText('Core never displays credential secrets');
    }

    public function test_workflow_activity_disappears_when_product_or_local_resource_access_is_lost(): void
    {
        $user = PlatformUser::query()->findOrFail($this->userId);
        $this->actingAs($user, 'platform')
            ->get(route('core.workspace.workflows', $this->workspaceId))
            ->assertSeeText('Deployment v12 succeeded');

        DB::connection('core')->table('workspace_product_access')
            ->where('membership_id', $this->membershipId)
            ->where('product', 'monitor')
            ->update(['revoked_at' => now()]);

        $this->get(route('core.workspace.workflows', $this->workspaceId))
            ->assertOk()
            ->assertSeeText('No recent product activity yet')
            ->assertDontSeeText('Deployment v12 succeeded');

        DB::connection('core')->table('workspace_product_access')
            ->where('membership_id', $this->membershipId)
            ->where('product', 'monitor')
            ->update(['revoked_at' => null]);
        $this->destinationStates->monitor = ProjectResourceDestinationState::AccessChanged;

        $this->get(route('core.workspace.workflows', $this->workspaceId))
            ->assertOk()
            ->assertDontSeeText('Deployment v12 succeeded');
    }

    public function test_workspace_activity_includes_deployer_builds_only_for_authorized_mapped_environments(): void
    {
        config(['platform.products.deployer.auth_authority' => 'core']);
        $this->addIdentityMap('user', '17', 'user', $this->userId);
        $this->addIdentityMap('organization', '50', 'workspace', $this->workspaceId);
        $this->seedDeployerProjectAndBuilds();
        $user = PlatformUser::query()->findOrFail($this->userId);
        $project = CoreProject::query()->findOrFail($this->projectId);
        $projectLink = app(DeployerProjectLink::class);
        $this->assertNotNull($projectLink->projectFor($user, $project));
        $environmentResource = ProjectResource::query()
            ->where('project_id', $this->projectId)
            ->where('product', 'deployer')
            ->where('resource_type', 'environment')
            ->where('resource_id', '41')
            ->firstOrFail();
        $this->assertNotNull($projectLink->accessibleEnvironment($user, $project, $environmentResource));
        $provider = app(WorkspaceActivityProviderRegistry::class)->get('deployer');
        $this->assertNotNull($provider);
        $workspace = Workspace::query()->findOrFail($this->workspaceId);
        $membership = app(WorkspaceProjectAccess::class)->activeMembership($user, $workspace);
        $this->assertNotNull($membership);
        $this->assertTrue(app(WorkspaceProjectAccess::class)->hasProductAccess($membership, 'deployer'));
        $accessibleProjects = CoreProject::query()
            ->where('workspace_id', $this->workspaceId)
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->whereHas('memberships', fn ($query) => $query->where('user_id', $this->userId)->where('status', 'active')->whereNull('revoked_at'))
            ->whereHas('products', fn ($query) => $query->whereIn('product', ['deployer', 'monitor'])->where('status', 'active'))
            ->with(['products' => fn ($query) => $query->whereIn('product', ['deployer', 'monitor'])->where('status', 'active')])
            ->get(['id', 'workspace_id', 'name', 'status', 'archived_at']);
        $this->assertCount(1, $accessibleProjects);
        $accessibleProject = $accessibleProjects->first();
        $this->assertTrue(app(WorkspaceProjectAccess::class)->canAccessProductResource($user, $accessibleProject, 'deployer'));
        $this->assertNotNull($projectLink->projectFor($user, $accessibleProject));
        $this->assertNotNull($projectLink->accessibleEnvironment($user, $accessibleProject, $environmentResource));
        $snapshot = $provider->recentForWorkspace($user, $workspace, $accessibleProjects, 30);
        $this->assertTrue($snapshot->available);
        $this->assertCount(2, $snapshot->runs);

        $response = $this->actingAs($user, 'platform')
            ->get(route('core.workspace.workflows', $this->workspaceId));

        $response
            ->assertOk()
            ->assertSeeText('Deployment abc123456789')
            ->assertSeeText('Production environment deployment')
            ->assertSeeText('Succeeded')
            ->assertSeeText('Deployment completed successfully. 15 of 15 stages are recorded.')
            ->assertSeeText('Needs approval')
            ->assertSeeText('Open in Deployer')
            ->assertDontSeeText('unmapped-build-revision');
        $response->assertSee(route('builds.show', ['build' => 100, 'organization_id' => 50]));

        DB::connection('core')->table('project_resources')
            ->where('product', 'deployer')
            ->where('resource_type', 'environment')
            ->where('resource_id', '41')
            ->update(['status' => 'disconnected']);

        $this->get(route('core.workspace.workflows', $this->workspaceId))
            ->assertOk()
            ->assertDontSeeText('Deployment abc123456789');

        DB::connection('core')->table('project_resources')
            ->where('product', 'deployer')
            ->where('resource_type', 'environment')
            ->where('resource_id', '41')
            ->update(['status' => 'active']);
        DB::connection('core')->table('workspace_product_access')
            ->where('membership_id', $this->membershipId)
            ->where('product', 'deployer')
            ->update(['revoked_at' => now()]);

        $this->get(route('core.workspace.workflows', $this->workspaceId))
            ->assertOk()
            ->assertDontSeeText('Deployment abc123456789');

        DB::connection('core')->table('workspace_product_access')
            ->where('membership_id', $this->membershipId)
            ->where('product', 'deployer')
            ->update(['revoked_at' => null]);
        DB::connection('deployer')->table('organizations')->where('id', 50)->update(['owner_id' => 999]);

        $this->get(route('core.workspace.workflows', $this->workspaceId))
            ->assertOk()
            ->assertDontSeeText('Deployment abc123456789');
    }

    public function test_deployer_activity_rejects_an_organization_mapped_to_another_core_workspace(): void
    {
        config(['platform.products.deployer.auth_authority' => 'core']);
        $this->addIdentityMap('user', '17', 'user', $this->userId);
        $this->addIdentityMap('organization', '50', 'workspace', $this->workspaceId);
        $this->seedDeployerProjectAndBuilds();

        $otherWorkspaceId = (string) Str::ulid();
        $otherMembershipId = (string) Str::ulid();
        DB::connection('core')->table('workspaces')->insert([
            'id' => $otherWorkspaceId,
            'owner_user_id' => $this->userId,
            'name' => 'Other workspace',
            'slug' => 'other-workspace',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('workspace_memberships')->insert([
            'id' => $otherMembershipId,
            'workspace_id' => $otherWorkspaceId,
            'user_id' => $this->userId,
            'role' => 'owner',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('workspace_product_access')->insert([
            'id' => (string) Str::ulid(),
            'membership_id' => $otherMembershipId,
            'product' => 'deployer',
            'role' => 'owner',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('legacy_identity_maps')
            ->where('source_product', 'deployer')
            ->where('source_entity', 'organization')
            ->where('source_id', '50')
            ->update(['canonical_id' => $otherWorkspaceId]);

        $user = PlatformUser::query()->findOrFail($this->userId);
        $project = CoreProject::query()->findOrFail($this->projectId);
        $environmentResource = ProjectResource::query()
            ->where('project_id', $this->projectId)
            ->where('product', 'deployer')
            ->where('resource_type', 'environment')
            ->where('resource_id', '41')
            ->firstOrFail();

        $this->assertNull(app(DeployerProjectLink::class)->accessibleEnvironment($user, $project, $environmentResource));
        $snapshot = app(WorkspaceActivityProviderRegistry::class)
            ->get('deployer')
            ?->recentForWorkspace($user, Workspace::query()->findOrFail($this->workspaceId), collect([$project]), 30);

        $this->assertNotNull($snapshot);
        $this->assertTrue($snapshot->available);
        $this->assertCount(0, $snapshot->runs);
    }

    public function test_workspace_activity_marks_a_product_unavailable_without_hiding_other_runs(): void
    {
        config(['platform.products.deployer.auth_authority' => 'core']);
        $this->addIdentityMap('user', '17', 'user', $this->userId);
        $this->addIdentityMap('organization', '50', 'workspace', $this->workspaceId);
        $this->seedDeployerProjectAndBuilds();
        Schema::connection('deployer')->dropIfExists('builds');

        $this->actingAs(PlatformUser::query()->findOrFail($this->userId), 'platform')
            ->get(route('core.workspace.workflows', $this->workspaceId))
            ->assertOk()
            ->assertSeeText('Recent activity from Deployer is temporarily unavailable')
            ->assertSeeText('Deployment v12 succeeded');
    }

    public function test_workflow_activity_includes_only_mapped_provisioning_state_and_redacts_source_secrets(): void
    {
        config(['platform.products.deployer.auth_authority' => 'core']);
        $this->addIdentityMap('user', '17', 'user', $this->userId);
        $this->addIdentityMap('organization', '50', 'workspace', $this->workspaceId);
        $this->seedDeployerProjectAndBuilds();
        DB::connection('deployer')->table('environments')->where('id', 41)->update([
            'server_id' => 501,
            'website_id' => 601,
        ]);
        DB::connection('deployer')->table('environments')->insert([
            'id' => 43,
            'project_id' => 31,
            'server_id' => 502,
            'website_id' => 602,
            'name' => 'Unmapped provisioning environment',
            'slug' => 'unmapped-provisioning',
            'type' => 'staging',
        ]);
        DB::connection('deployer')->table('builds')->insert([
            'id' => 103,
            'environment_id' => 41,
            'status' => 'running',
            'revision' => 'fed987654321abcdef',
            'release_name' => null,
            'setup_stage' => 6,
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinute(),
            'started_at' => now()->subMinute(),
            'finished_at' => null,
        ]);
        DB::connection('deployer')->table('servers')->insert([
            [
                'id' => 501,
                'organization_id' => 50,
                'provider_id' => null,
                'name' => 'Checkout application server',
                'type' => 'app',
                'setup_stage' => 3,
                'provisioning_status' => 'failed',
                'provisioning_error' => 'provider_secret_must_not_render',
                'public_ip' => '198.51.100.88',
                'password' => 'server_password_must_not_render',
                'provisioned_at' => null,
                'created_at' => now()->subMinutes(5),
                'updated_at' => now()->subMinute(),
            ],
            [
                'id' => 502,
                'organization_id' => 999,
                'provider_id' => null,
                'name' => 'Cross-organization server',
                'type' => 'app',
                'setup_stage' => 4,
                'provisioning_status' => 'provisioning',
                'provisioning_error' => null,
                'public_ip' => null,
                'password' => null,
                'provisioned_at' => null,
                'created_at' => now()->subMinutes(4),
                'updated_at' => now()->subMinute(),
            ],
        ]);
        DB::connection('deployer')->table('websites')->insert([
            [
                'id' => 601,
                'server_id' => 501,
                'organization_id' => 50,
                'name' => 'Checkout website',
                'setup_stage' => 1,
                'provisioning_status' => 'queued',
                'provisioning_error' => null,
                'database_password' => 'database_password_must_not_render',
                'provisioned_at' => null,
                'created_at' => now()->subMinutes(3),
                'updated_at' => now()->subMinutes(3),
                'deleted_at' => null,
            ],
            [
                'id' => 602,
                'server_id' => 502,
                'organization_id' => 999,
                'name' => 'Cross-organization website',
                'setup_stage' => 1,
                'provisioning_status' => 'provisioning',
                'provisioning_error' => null,
                'database_password' => null,
                'provisioned_at' => null,
                'created_at' => now()->subMinutes(2),
                'updated_at' => now()->subMinute(),
                'deleted_at' => null,
            ],
        ]);

        DB::connection('core')->table('project_resources')->insert([
            'id' => (string) Str::ulid(),
            'project_id' => $this->projectId,
            'product' => 'deployer',
            'resource_type' => 'environment',
            'resource_id' => '43',
            'name' => 'Unmapped provisioning environment',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = PlatformUser::query()->findOrFail($this->userId);
        $response = $this->actingAs($user, 'platform')
            ->get(route('core.workspace.workflows', $this->workspaceId));

        $response
            ->assertOk()
            ->assertSeeText('Server provisioning')
            ->assertSeeText('Production environment server')
            ->assertSeeText('Provisioning failed. 3 of 12 stages are recorded.')
            ->assertSeeText('Website provisioning')
            ->assertSeeText('Production environment website')
            ->assertSeeText('Provisioning is queued. 1 of 3 stages are recorded.')
            ->assertSeeText('Deployment fed987654321')
            ->assertSeeText('6 of 15 stages are recorded.')
            ->assertSee(route('servers.show', ['server' => 501, 'organization_id' => 50]))
            ->assertSee(route('websites.show', ['website' => 601, 'organization_id' => 50]))
            ->assertDontSeeText('Unmapped provisioning environment server')
            ->assertDontSeeText('Unmapped provisioning environment website')
            ->assertDontSeeText('Cross-organization server')
            ->assertDontSeeText('Cross-organization website')
            ->assertDontSeeText('provider_secret_must_not_render')
            ->assertDontSeeText('server_password_must_not_render')
            ->assertDontSeeText('database_password_must_not_render')
            ->assertDontSeeText('198.51.100.88');
    }

    public function test_deployer_backup_restore_activity_is_mapped_bounded_and_redacted(): void
    {
        config(['platform.products.deployer.auth_authority' => 'core']);
        $this->addIdentityMap('user', '17', 'user', $this->userId);
        $this->addIdentityMap('organization', '50', 'workspace', $this->workspaceId);
        $this->seedDeployerProjectAndBuilds();
        $this->createDeployerBackupActivityTables();

        DB::connection('deployer')->table('environments')->where('id', 41)->update([
            'server_id' => 501,
            'website_id' => 601,
        ]);
        DB::connection('deployer')->table('servers')->insert([
            'id' => 501,
            'organization_id' => 50,
            'type' => 'app',
            'setup_stage' => 12,
            'provisioning_status' => 'provisioned',
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);
        DB::connection('deployer')->table('websites')->insert([
            'id' => 601,
            'server_id' => 501,
            'organization_id' => 50,
            'name' => 'Checkout website',
            'setup_stage' => 3,
            'provisioning_status' => 'provisioned',
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
            'deleted_at' => null,
        ]);
        DB::connection('deployer')->table('website_backups')->insert([
            [
                'id' => 701,
                'website_id' => 601,
                'status' => 'running',
                'snapshot_id' => 'backup_snapshot_secret',
                'error' => 'backup_provider_error_secret',
                'started_at' => now()->subMinute(),
                'completed_at' => null,
                'created_at' => now()->subMinutes(2),
                'updated_at' => now()->subMinute(),
            ],
            [
                'id' => 702,
                'website_id' => 601,
                'status' => 'succeeded',
                'snapshot_id' => 'old_backup_snapshot_secret',
                'error' => null,
                'started_at' => now()->subDays(45),
                'completed_at' => now()->subDays(45),
                'created_at' => now()->subDays(45),
                'updated_at' => now()->subDays(45),
            ],
        ]);
        DB::connection('deployer')->table('backup_restores')->insert([
            'id' => 711,
            'website_backup_id' => 701,
            'status' => 'failed',
            'error' => 'restore_provider_error_secret',
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
            'created_at' => now()->subMinute(),
            'updated_at' => now(),
        ]);
        DB::connection('deployer')->table('backup_restore_verifications')->insert([
            'id' => 721,
            'website_backup_id' => 701,
            'status' => 'queued',
            'snapshot_id' => 'verification_snapshot_secret',
            'error' => 'verification_provider_error_secret',
            'started_at' => null,
            'completed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = PlatformUser::query()->findOrFail($this->userId);
        $workspace = Workspace::query()->findOrFail($this->workspaceId);
        $project = CoreProject::query()->findOrFail($this->projectId);
        $snapshot = app(WorkspaceActivityProviderRegistry::class)
            ->get('deployer')
            ?->recentForWorkspace($user, $workspace, collect([$project]), 30);

        $this->assertNotNull($snapshot);
        $this->assertTrue($snapshot->available);
        $backupRuns = $snapshot->runs->filter(fn ($run): bool => str_starts_with($run->key, 'deployer:website-backup:'));
        $restoreRuns = $snapshot->runs->filter(fn ($run): bool => str_starts_with($run->key, 'deployer:backup-restore:'));
        $verificationRuns = $snapshot->runs->filter(fn ($run): bool => str_starts_with($run->key, 'deployer:backup-verification:'));

        $this->assertCount(1, $backupRuns);
        $this->assertSame('deployer:website-backup:701', $backupRuns->first()->key);
        $this->assertSame('deployer:backup-restore:711', $restoreRuns->first()->key);
        $this->assertSame('deployer:backup-verification:721', $verificationRuns->first()->key);
        $this->assertSame('processing', $backupRuns->first()->steps[0]->state->value);
        $this->assertSame('failed', $restoreRuns->first()->steps[0]->state->value);
        $this->assertSame('pending', $verificationRuns->first()->steps[0]->state->value);
        $this->assertSame(
            route('backups.index', ['organization_id' => 50]).'#backup-history-list',
            $restoreRuns->first()->steps[0]->resultUrl,
        );

        foreach ($snapshot->runs as $run) {
            foreach ($run->steps as $step) {
                $this->assertStringNotContainsString('backup_snapshot_secret', $step->detail);
                $this->assertStringNotContainsString('old_backup_snapshot_secret', $step->detail);
                $this->assertStringNotContainsString('restore_provider_error_secret', $step->detail);
                $this->assertStringNotContainsString('verification_provider_error_secret', $step->detail);
                $this->assertStringNotContainsString('backup_provider_error_secret', $step->detail);
            }
        }
    }

    public function test_deployer_database_operation_activity_is_project_mapped_bounded_and_redacted(): void
    {
        config(['platform.products.deployer.auth_authority' => 'core']);
        $this->addIdentityMap('user', '17', 'user', $this->userId);
        $this->addIdentityMap('organization', '50', 'workspace', $this->workspaceId);
        $this->seedDeployerProjectAndBuilds();
        $this->createDeployerDatabaseOperationActivityTables();

        DB::connection('deployer')->table('environment_resources')->insert([
            ['id' => 821, 'environment_id' => 41, 'type' => 'mysql'],
            ['id' => 822, 'environment_id' => 42, 'type' => 'mysql'],
            ['id' => 823, 'environment_id' => 41, 'type' => 'object_storage'],
        ]);
        DB::connection('deployer')->table('database_operation_runs')->insert([
            [
                'id' => 831,
                'environment_resource_id' => 821,
                'operation' => 'inspection',
                'status' => 'succeeded',
                'attempts' => 1,
                'started_at' => now()->subMinute(),
                'finished_at' => now(),
                'lease_expires_at' => null,
                'created_at' => now()->subMinutes(2),
                'updated_at' => now(),
            ],
            [
                'id' => 832,
                'environment_resource_id' => 821,
                'operation' => 'inspection',
                'status' => 'succeeded',
                'attempts' => 1,
                'started_at' => now()->subDays(31),
                'finished_at' => now()->subDays(31),
                'lease_expires_at' => null,
                'created_at' => now()->subDays(31),
                'updated_at' => now()->subDays(31),
            ],
            [
                'id' => 833,
                'environment_resource_id' => 822,
                'operation' => 'inspection',
                'status' => 'queued',
                'attempts' => 0,
                'started_at' => null,
                'finished_at' => null,
                'lease_expires_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 834,
                'environment_resource_id' => 823,
                'operation' => 'inspection',
                'status' => 'succeeded',
                'attempts' => 1,
                'started_at' => now(),
                'finished_at' => now(),
                'lease_expires_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 835,
                'environment_resource_id' => 821,
                'operation' => 'user_apply',
                'subject_id' => 987654,
                'status' => 'running',
                'attempts' => 1,
                'started_at' => now()->subMinute(),
                'finished_at' => null,
                'lease_expires_at' => now()->addMinutes(10),
                'created_at' => now()->subMinutes(2),
                'updated_at' => now(),
            ],
            [
                'id' => 836,
                'environment_resource_id' => 821,
                'operation' => 'user_remove',
                'subject_id' => 987654,
                'status' => 'failed',
                'attempts' => 3,
                'started_at' => now()->subMinutes(2),
                'finished_at' => now(),
                'lease_expires_at' => null,
                'created_at' => now()->subMinutes(3),
                'updated_at' => now(),
            ],
            [
                'id' => 837,
                'environment_resource_id' => 821,
                'operation' => 'inspection',
                'status' => 'running',
                'attempts' => 1,
                'started_at' => now()->subMinutes(20),
                'finished_at' => null,
                'lease_expires_at' => now()->subMinute(),
                'created_at' => now()->subMinutes(21),
                'updated_at' => now()->subMinutes(20),
            ],
            [
                'id' => 838,
                'environment_resource_id' => 821,
                'operation' => 'inspection',
                'status' => 'queued',
                'attempts' => 0,
                'started_at' => null,
                'finished_at' => null,
                'lease_expires_at' => null,
                'created_at' => now()->subMinutes(11),
                'updated_at' => now()->subMinutes(11),
            ],
        ]);

        $user = PlatformUser::query()->findOrFail($this->userId);
        $workspace = Workspace::query()->findOrFail($this->workspaceId);
        $project = CoreProject::query()->findOrFail($this->projectId);
        $snapshot = app(WorkspaceActivityProviderRegistry::class)
            ->get('deployer')
            ?->recentForWorkspace($user, $workspace, collect([$project]), 30);

        $this->assertNotNull($snapshot);
        $this->assertTrue($snapshot->available);
        $runsByKey = $snapshot->runs->keyBy('key');

        $this->assertTrue($runsByKey->has('deployer:database-operation:831'));
        $this->assertFalse($runsByKey->has('deployer:database-operation:832'));
        $this->assertFalse($runsByKey->has('deployer:database-operation:833'));
        $this->assertFalse($runsByKey->has('deployer:database-operation:834'));
        $this->assertSame('processing', $runsByKey['deployer:database-operation:835']->steps[0]->state->value);
        $this->assertSame('failed', $runsByKey['deployer:database-operation:836']->steps[0]->state->value);
        $this->assertSame('unknown', $runsByKey['deployer:database-operation:837']->steps[0]->state->value);
        $this->assertSame('unknown', $runsByKey['deployer:database-operation:838']->steps[0]->state->value);
        $this->assertSame('succeeded', $runsByKey['deployer:database-operation:831']->steps[0]->state->value);
        $this->assertSame(
            route('databases.index', ['organization_id' => 50]).'#database-insights',
            $runsByKey['deployer:database-operation:831']->steps[0]->resultUrl,
        );

        foreach ($snapshot->runs as $run) {
            foreach ($run->steps as $step) {
                foreach (['987654', 'secret_schema_name', 'private_metric_value'] as $privateData) {
                    $this->assertStringNotContainsString($privateData, $step->detail);
                }
            }
        }
    }

    public function test_deployer_scheduled_tasks_database_clones_and_server_commands_use_mapped_resources(): void
    {
        config(['platform.products.deployer.auth_authority' => 'core']);
        $this->addIdentityMap('user', '17', 'user', $this->userId);
        $this->addIdentityMap('organization', '50', 'workspace', $this->workspaceId);
        $this->seedDeployerProjectAndBuilds();
        $this->createDeployerOperationalActivityTables();
        DB::connection('deployer')->table('environments')->where('id', 41)->update(['server_id' => 501]);
        DB::connection('deployer')->table('environments')->insert([
            'id' => 43,
            'project_id' => 31,
            'server_id' => null,
            'website_id' => null,
            'name' => 'Unmapped environment',
            'slug' => 'unmapped',
            'type' => 'staging',
        ]);
        DB::connection('deployer')->table('environments')->insert([
            'id' => 44,
            'project_id' => 31,
            'server_id' => 502,
            'website_id' => null,
            'name' => 'Wrong server organization',
            'slug' => 'wrong-server-organization',
            'type' => 'staging',
        ]);
        DB::connection('deployer')->table('environments')->insert([
            ['id' => 45, 'project_id' => 31, 'server_id' => 503, 'website_id' => null, 'name' => 'Expired diagnostic lease', 'slug' => 'expired-diagnostic-lease', 'type' => 'staging'],
            ['id' => 46, 'project_id' => 31, 'server_id' => 504, 'website_id' => null, 'name' => 'Old diagnostic result', 'slug' => 'old-diagnostic-result', 'type' => 'staging'],
        ]);
        DB::connection('deployer')->table('servers')->insert([
            ['id' => 501, 'organization_id' => 50, 'type' => 'app', 'setup_stage' => 12, 'provisioning_status' => 'provisioned', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 502, 'organization_id' => 999, 'type' => 'app', 'setup_stage' => 1, 'provisioning_status' => 'queued', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 503, 'organization_id' => 50, 'type' => 'app', 'setup_stage' => 12, 'provisioning_status' => 'provisioned', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 504, 'organization_id' => 50, 'type' => 'app', 'setup_stage' => 12, 'provisioning_status' => 'provisioned', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::connection('core')->table('project_resources')->insert([
            'id' => (string) Str::ulid(),
            'project_id' => $this->projectId,
            'product' => 'deployer',
            'resource_type' => 'environment',
            'resource_id' => '42',
            'name' => 'Staging environment',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('project_resources')->insert([
            ['id' => (string) Str::ulid(), 'project_id' => $this->projectId, 'product' => 'deployer', 'resource_type' => 'environment', 'resource_id' => '45', 'name' => 'Expired diagnostic lease', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['id' => (string) Str::ulid(), 'project_id' => $this->projectId, 'product' => 'deployer', 'resource_type' => 'environment', 'resource_id' => '46', 'name' => 'Old diagnostic result', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::connection('core')->table('project_resources')->insert([
            'id' => (string) Str::ulid(),
            'project_id' => $this->projectId,
            'product' => 'deployer',
            'resource_type' => 'environment',
            'resource_id' => '44',
            'name' => 'Wrong server organization',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('deployer')->table('scheduled_tasks')->insert([
            ['id' => 801, 'environment_id' => 41, 'name' => 'private_task_name', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 802, 'environment_id' => 43, 'name' => 'unmapped_task', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::connection('deployer')->table('scheduled_task_runs')->insert([
            ['id' => 811, 'scheduled_task_id' => 801, 'status' => 'failed', 'output' => 'scheduled_output_secret', 'started_at' => now()->subMinute(), 'finished_at' => now(), 'created_at' => now()->subMinute(), 'updated_at' => now()],
            ['id' => 812, 'scheduled_task_id' => 802, 'status' => 'succeeded', 'output' => 'unmapped_output_secret', 'started_at' => now(), 'finished_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::connection('deployer')->table('environment_resources')->insert([
            ['id' => 821, 'environment_id' => 41],
            ['id' => 822, 'environment_id' => 42],
            ['id' => 823, 'environment_id' => 43],
        ]);
        DB::connection('deployer')->table('database_clones')->insert([
            ['id' => 831, 'source_resource_id' => 821, 'target_resource_id' => 822, 'status' => 'running', 'error' => 'clone_error_secret', 'started_at' => now()->subMinute(), 'finished_at' => null, 'created_at' => now()->subMinute(), 'updated_at' => now()],
            ['id' => 832, 'source_resource_id' => 821, 'target_resource_id' => 823, 'status' => 'succeeded', 'error' => null, 'started_at' => now(), 'finished_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::connection('deployer')->table('server_command_executions')->insert([
            ['id' => 841, 'server_id' => 501, 'user_id' => 17, 'command' => 'private_command_secret', 'status' => 'canceled', 'output' => 'command_output_secret', 'exit_code' => null, 'started_at' => now()->subMinute(), 'finished_at' => now(), 'created_at' => now()->subMinute(), 'updated_at' => now()],
            ['id' => 842, 'server_id' => 502, 'user_id' => 17, 'command' => 'unmapped_command', 'status' => 'succeeded', 'output' => 'unmapped_command_output', 'exit_code' => 0, 'started_at' => now(), 'finished_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::connection('deployer')->table('server_diagnostic_snapshots')->insert([
            ['id' => 851, 'server_id' => 501, 'status' => 'queued', 'checks' => '[{"detail":"diagnostic_check_secret"}]', 'failure_stage' => 'connection', 'error' => 'diagnostic_error_secret', 'attempt' => 1, 'attempt_token' => 'diagnostic_attempt_secret', 'lease_expires_at' => now()->addMinutes(2), 'started_at' => null, 'finished_at' => null, 'created_at' => now()->subMinute(), 'updated_at' => now()],
            ['id' => 852, 'server_id' => 503, 'status' => 'running', 'checks' => '[{"detail":"stale_diagnostic_secret"}]', 'failure_stage' => null, 'error' => null, 'attempt' => 2, 'attempt_token' => 'stale_attempt_secret', 'lease_expires_at' => now()->subMinute(), 'started_at' => now()->subMinutes(2), 'finished_at' => null, 'created_at' => now()->subMinutes(2), 'updated_at' => now()],
            ['id' => 853, 'server_id' => 504, 'status' => 'failed', 'checks' => null, 'failure_stage' => 'connection', 'error' => 'old_diagnostic_secret', 'attempt' => 1, 'attempt_token' => null, 'lease_expires_at' => null, 'started_at' => now()->subDays(31), 'finished_at' => now()->subDays(31), 'created_at' => now()->subDays(31), 'updated_at' => now()->subDays(31)],
            ['id' => 854, 'server_id' => 502, 'status' => 'failed', 'checks' => '[{"detail":"wrong_org_diagnostic_secret"}]', 'failure_stage' => 'connection', 'error' => 'wrong_org_diagnostic_secret', 'attempt' => 1, 'attempt_token' => 'wrong_org_attempt_secret', 'lease_expires_at' => null, 'started_at' => now(), 'finished_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::connection('deployer')->table('load_balancers')->insert([
            ['id' => 861, 'organization_id' => 50, 'environment_id' => 41, 'server_id' => 501, 'hostname' => 'private.hostname.secret', 'status' => 'failed', 'last_error' => 'private_load_balancer_error', 'applied_at' => null, 'created_at' => now()->subMinute(), 'updated_at' => now()],
            ['id' => 862, 'organization_id' => 999, 'environment_id' => 44, 'server_id' => 502, 'hostname' => 'wrong-org.hostname.secret', 'status' => 'failed', 'last_error' => 'wrong_organization_load_balancer_error', 'applied_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 863, 'organization_id' => 50, 'environment_id' => 42, 'server_id' => 501, 'hostname' => 'unmapped.hostname.secret', 'status' => 'pending', 'last_error' => null, 'applied_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 864, 'organization_id' => 50, 'environment_id' => 41, 'server_id' => 501, 'hostname' => 'active.hostname.secret', 'status' => 'active', 'last_error' => null, 'applied_at' => now()->subDay(), 'created_at' => now()->subDays(2), 'updated_at' => now()->subDay()],
            ['id' => 865, 'organization_id' => 50, 'environment_id' => 41, 'server_id' => 501, 'hostname' => 'old.hostname.secret', 'status' => 'active', 'last_error' => null, 'applied_at' => now()->subDays(31), 'created_at' => now()->subDays(32), 'updated_at' => now()->subDays(31)],
            ['id' => 866, 'organization_id' => 50, 'environment_id' => 41, 'server_id' => 501, 'hostname' => 'removing.hostname.secret', 'status' => 'removing', 'last_error' => null, 'applied_at' => null, 'created_at' => now()->subHour(), 'updated_at' => now()],
            ['id' => 867, 'organization_id' => 50, 'environment_id' => 41, 'server_id' => 501, 'hostname' => 'removal-failed.hostname.secret', 'status' => 'removal_failed', 'last_error' => 'private_removal_failure_detail', 'applied_at' => null, 'created_at' => now()->subHour(), 'updated_at' => now()],
        ]);

        $user = PlatformUser::query()->findOrFail($this->userId);
        $workspace = Workspace::query()->findOrFail($this->workspaceId);
        $project = CoreProject::query()->findOrFail($this->projectId);
        $snapshot = app(WorkspaceActivityProviderRegistry::class)
            ->get('deployer')
            ?->recentForWorkspace($user, $workspace, collect([$project]), 30);

        $this->assertNotNull($snapshot);
        $this->assertTrue($snapshot->available);
        $runsByKey = $snapshot->runs->keyBy('key');
        $this->assertTrue($runsByKey->has('deployer:scheduled-task:811'));
        $this->assertTrue($runsByKey->has('deployer:database-clone:831'));
        $this->assertTrue($runsByKey->has('deployer:server-command:841'));
        $this->assertTrue($runsByKey->has('deployer:server-diagnostic:851'));
        $this->assertTrue($runsByKey->has('deployer:server-diagnostic:852'));
        $this->assertTrue($runsByKey->has('deployer:load-balancer:861'));
        $this->assertTrue($runsByKey->has('deployer:load-balancer:864'));
        $this->assertTrue($runsByKey->has('deployer:load-balancer:866'));
        $this->assertTrue($runsByKey->has('deployer:load-balancer:867'));
        $this->assertFalse($runsByKey->has('deployer:scheduled-task:812'));
        $this->assertFalse($runsByKey->has('deployer:database-clone:832'));
        $this->assertFalse($runsByKey->has('deployer:server-command:842'));
        $this->assertFalse($runsByKey->has('deployer:server-diagnostic:853'));
        $this->assertFalse($runsByKey->has('deployer:server-diagnostic:854'));
        $this->assertFalse($runsByKey->has('deployer:load-balancer:862'));
        $this->assertFalse($runsByKey->has('deployer:load-balancer:863'));
        $this->assertFalse($runsByKey->has('deployer:load-balancer:865'));
        $this->assertSame('failed', $runsByKey['deployer:scheduled-task:811']->steps[0]->state->value);
        $this->assertSame('processing', $runsByKey['deployer:database-clone:831']->steps[0]->state->value);
        $this->assertSame('discarded', $runsByKey['deployer:server-command:841']->steps[0]->state->value);
        $this->assertSame('pending', $runsByKey['deployer:server-diagnostic:851']->steps[0]->state->value);
        $this->assertSame('unknown', $runsByKey['deployer:server-diagnostic:852']->steps[0]->state->value);
        $this->assertSame('failed', $runsByKey['deployer:load-balancer:861']->steps[0]->state->value);
        $this->assertSame('succeeded', $runsByKey['deployer:load-balancer:864']->steps[0]->state->value);
        $this->assertSame('pending', $runsByKey['deployer:load-balancer:866']->steps[0]->state->value);
        $this->assertSame('failed', $runsByKey['deployer:load-balancer:867']->steps[0]->state->value);
        $this->assertSame('Remote load-balancer cleanup is in progress.', $runsByKey['deployer:load-balancer:866']->steps[0]->detail);
        $this->assertSame('Remote load-balancer cleanup failed. Open Deployer to retry.', $runsByKey['deployer:load-balancer:867']->steps[0]->detail);
        $this->assertSame(
            route('servers.commands.index', ['server' => 501, 'organization_id' => 50]),
            $runsByKey['deployer:server-command:841']->steps[0]->resultUrl,
        );
        $this->assertSame(
            route('servers.show', ['server' => 501, 'organization_id' => 50]),
            $runsByKey['deployer:server-diagnostic:851']->steps[0]->resultUrl,
        );
        $this->assertSame(
            route('load-balancers.index', ['organization_id' => 50]),
            $runsByKey['deployer:load-balancer:861']->steps[0]->resultUrl,
        );

        foreach ($snapshot->runs as $run) {
            foreach ($run->steps as $step) {
                foreach ([
                    'scheduled_output_secret',
                    'clone_error_secret',
                    'private_command_secret',
                    'command_output_secret',
                    'unmapped_output_secret',
                    'unmapped_command_output',
                    'diagnostic_check_secret',
                    'diagnostic_error_secret',
                    'diagnostic_attempt_secret',
                    'stale_diagnostic_secret',
                    'stale_attempt_secret',
                    'wrong_org_diagnostic_secret',
                    'wrong_org_attempt_secret',
                    'private_load_balancer_error',
                    'private_removal_failure_detail',
                    'wrong_organization_load_balancer_error',
                    'removing.hostname.secret',
                    'removal-failed.hostname.secret',
                    'private.hostname.secret',
                ] as $secret) {
                    $this->assertStringNotContainsString($secret, $step->detail);
                }
            }
        }
    }

    public function test_deployer_repository_webhook_activity_is_project_mapped_bounded_and_redacted(): void
    {
        config(['platform.products.deployer.auth_authority' => 'core']);
        $this->addIdentityMap('user', '17', 'user', $this->userId);
        $this->addIdentityMap('organization', '50', 'workspace', $this->workspaceId);
        $this->seedDeployerProjectAndBuilds();
        $this->createDeployerRepositoryWebhookActivityTable();

        DB::connection('deployer')->table('websites')->insert([
            'id' => 601,
            'server_id' => null,
            'organization_id' => 50,
            'name' => 'Storefront website',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('deployer')->table('environments')->where('id', 41)->update(['website_id' => 601]);
        DB::connection('deployer')->table('repositories')->insert([
            ['id' => 1001, 'website_id' => 601, 'provider_id' => null, 'organization_id' => 50, 'deleted_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 1002, 'website_id' => 601, 'provider_id' => null, 'organization_id' => 999, 'deleted_at' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::connection('deployer')->table('repository_webhook_deliveries')->insert([
            ['id' => 1101, 'repository_id' => 1001, 'delivery_id' => 'delivery_pending_secret', 'revision' => 'revision_secret_pending', 'commit_message' => 'commit_secret_pending', 'status' => 'pending', 'build_id' => null, 'changed_paths' => '["src/private.php"]', 'created_at' => now()->subMinutes(5), 'updated_at' => now()],
            ['id' => 1102, 'repository_id' => 1001, 'delivery_id' => 'delivery_build_secret', 'revision' => 'revision_secret_build', 'commit_message' => 'commit_secret_build', 'status' => 'queued', 'build_id' => 100, 'changed_paths' => '["src/build.php"]', 'created_at' => now()->subMinutes(4), 'updated_at' => now()],
            ['id' => 1103, 'repository_id' => 1001, 'delivery_id' => 'delivery_unavailable_secret', 'revision' => 'revision_secret_unavailable', 'commit_message' => 'commit_secret_unavailable', 'status' => 'unavailable', 'build_id' => null, 'changed_paths' => '["src/blocked.php"]', 'created_at' => now()->subMinutes(3), 'updated_at' => now()],
            ['id' => 1104, 'repository_id' => 1001, 'delivery_id' => 'delivery_skipped_secret', 'revision' => 'revision_secret_skipped', 'commit_message' => 'commit_secret_skipped', 'status' => 'skipped', 'build_id' => null, 'changed_paths' => '["src/ignored.php"]', 'created_at' => now()->subMinutes(2), 'updated_at' => now()],
            ['id' => 1105, 'repository_id' => 1001, 'delivery_id' => 'delivery_superseded_secret', 'revision' => 'revision_secret_superseded', 'commit_message' => 'commit_secret_superseded', 'status' => 'superseded', 'build_id' => null, 'changed_paths' => '["src/older.php"]', 'created_at' => now()->subMinute(), 'updated_at' => now()],
            ['id' => 1106, 'repository_id' => 1001, 'delivery_id' => 'delivery_received_secret', 'revision' => 'revision_secret_received', 'commit_message' => 'commit_secret_received', 'status' => 'received', 'build_id' => null, 'changed_paths' => '["src/unclassified.php"]', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 1107, 'repository_id' => 1002, 'delivery_id' => 'delivery_other_org_secret', 'revision' => 'revision_secret_other_org', 'commit_message' => 'other_org_secret', 'status' => 'pending', 'build_id' => null, 'changed_paths' => '["src/other.php"]', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 1108, 'repository_id' => 1001, 'delivery_id' => 'delivery_old_secret', 'revision' => 'revision_secret_old', 'commit_message' => 'old_terminal_secret', 'status' => 'skipped', 'build_id' => null, 'changed_paths' => '["src/old.php"]', 'created_at' => now()->subDays(31), 'updated_at' => now()->subDays(31)],
        ]);

        $user = PlatformUser::query()->findOrFail($this->userId);
        $workspace = Workspace::query()->findOrFail($this->workspaceId);
        $project = CoreProject::query()->findOrFail($this->projectId);
        $snapshot = app(WorkspaceActivityProviderRegistry::class)
            ->get('deployer')
            ?->recentForWorkspace($user, $workspace, collect([$project]), 30);

        $this->assertNotNull($snapshot);
        $this->assertTrue($snapshot->available);
        $webhookRuns = $snapshot->runs->filter(fn ($run): bool => str_starts_with($run->key, 'deployer:webhook-delivery:'));
        $this->assertCount(5, $webhookRuns);
        $runsByKey = $webhookRuns->keyBy('key');
        $this->assertTrue($runsByKey->has('deployer:webhook-delivery:1101'));
        $this->assertTrue($runsByKey->has('deployer:webhook-delivery:1103'));
        $this->assertTrue($runsByKey->has('deployer:webhook-delivery:1104'));
        $this->assertTrue($runsByKey->has('deployer:webhook-delivery:1105'));
        $this->assertTrue($runsByKey->has('deployer:webhook-delivery:1106'));
        $this->assertFalse($runsByKey->has('deployer:webhook-delivery:1102'));
        $this->assertFalse($runsByKey->has('deployer:webhook-delivery:1107'));
        $this->assertFalse($runsByKey->has('deployer:webhook-delivery:1108'));
        $this->assertSame('pending', $runsByKey['deployer:webhook-delivery:1101']->steps[0]->state->value);
        $this->assertSame('blocked', $runsByKey['deployer:webhook-delivery:1103']->steps[0]->state->value);
        $this->assertSame('discarded', $runsByKey['deployer:webhook-delivery:1104']->steps[0]->state->value);
        $this->assertSame('discarded', $runsByKey['deployer:webhook-delivery:1105']->steps[0]->state->value);
        $this->assertSame('unknown', $runsByKey['deployer:webhook-delivery:1106']->steps[0]->state->value);
        $this->assertSame(
            route('repositories.show', ['repository' => 1001, 'organization_id' => 50]),
            $runsByKey['deployer:webhook-delivery:1101']->steps[0]->resultUrl,
        );

        $deliveryHistory = app(WorkspaceWebhookDeliveryProviderRegistry::class)
            ->get('deployer')
            ?->recentWebhookDeliveriesForWorkspace($user, $workspace, collect([$project]), 30);

        $this->assertNotNull($deliveryHistory);
        $this->assertTrue($deliveryHistory->available);
        $this->assertCount(6, $deliveryHistory->deliveries);
        $this->assertTrue($deliveryHistory->deliveries->every(fn ($delivery): bool => $delivery->product === 'deployer'
            && $delivery->projectName === 'Checkout app'
            && $delivery->title === 'Repository webhook'
            && $delivery->attemptCount === null));
        $this->assertFalse($deliveryHistory->deliveries->contains(fn ($delivery): bool => str_contains($delivery->key, '1107')
            || str_contains($delivery->key, '1108')));

        foreach ($webhookRuns as $run) {
            $this->assertSame((string) $project->getKey(), $run->projectId);
            $this->assertSame(route('core.projects.show', [$workspace, $project]), $run->projectUrl);

            foreach ($run->steps as $step) {
                foreach ([
                    'delivery_pending_secret',
                    'delivery_build_secret',
                    'delivery_unavailable_secret',
                    'delivery_skipped_secret',
                    'delivery_superseded_secret',
                    'delivery_received_secret',
                    'delivery_other_org_secret',
                    'delivery_old_secret',
                    'revision_secret_',
                    'commit_secret_',
                    'old_terminal_secret',
                    'other_org_secret',
                    'src/private.php',
                    'src/ignored.php',
                    'src/older.php',
                    'src/unclassified.php',
                    'src/other.php',
                ] as $secret) {
                    $this->assertStringNotContainsString($secret, $run->title.' '.$step->title.' '.$step->detail);
                }
            }
        }

        $otherProjectId = (string) Str::ulid();
        DB::connection('core')->table('projects')->insert([
            'id' => $otherProjectId,
            'workspace_id' => $this->workspaceId,
            'created_by_user_id' => $this->userId,
            'name' => 'Shared website project',
            'slug' => 'shared-website-project',
            'status' => 'active',
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('project_memberships')->insert([
            'id' => (string) Str::ulid(),
            'project_id' => $otherProjectId,
            'user_id' => $this->userId,
            'role' => 'owner',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('project_products')->insert([
            'id' => (string) Str::ulid(),
            'project_id' => $otherProjectId,
            'product' => 'deployer',
            'status' => 'active',
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('project_resources')->insert([
            'id' => (string) Str::ulid(),
            'project_id' => $otherProjectId,
            'product' => 'deployer',
            'resource_type' => 'environment',
            'resource_id' => '41',
            'name' => 'Shared production environment',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ambiguousSnapshot = app(WorkspaceActivityProviderRegistry::class)
            ->get('deployer')
            ?->recentForWorkspace($user, $workspace, collect([$project, CoreProject::query()->findOrFail($otherProjectId)]), 30);

        $this->assertNotNull($ambiguousSnapshot);
        $this->assertTrue($ambiguousSnapshot->available);
        $this->assertFalse($ambiguousSnapshot->runs->contains(
            fn ($run): bool => str_starts_with($run->key, 'deployer:webhook-delivery:'),
        ));
    }

    public function test_deployer_website_health_and_log_activity_uses_safe_mappings_and_hides_source_details(): void
    {
        config(['platform.products.deployer.auth_authority' => 'core']);
        $this->addIdentityMap('user', '17', 'user', $this->userId);
        $this->addIdentityMap('organization', '50', 'workspace', $this->workspaceId);
        $this->seedDeployerProjectAndBuilds();
        $this->createDeployerOperationalActivityTables();
        $this->createDeployerLogSnapshotTables();

        DB::connection('deployer')->table('environments')->where('id', 41)->update([
            'server_id' => 501,
            'website_id' => 601,
        ]);
        DB::connection('deployer')->table('environments')->insert([
            'id' => 43,
            'project_id' => 31,
            'server_id' => 502,
            'website_id' => 602,
            'name' => 'Cross-organization resources',
            'slug' => 'cross-organization-resources',
            'type' => 'staging',
        ]);
        DB::connection('core')->table('project_resources')->insert([
            'id' => (string) Str::ulid(),
            'project_id' => $this->projectId,
            'product' => 'deployer',
            'resource_type' => 'environment',
            'resource_id' => '43',
            'name' => 'Cross-organization environment',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('deployer')->table('servers')->insert([
            ['id' => 501, 'organization_id' => 50, 'type' => 'app', 'setup_stage' => 12, 'provisioning_status' => 'provisioned', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 502, 'organization_id' => 999, 'type' => 'app', 'setup_stage' => 12, 'provisioning_status' => 'provisioned', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::connection('deployer')->table('websites')->insert([
            ['id' => 601, 'server_id' => 501, 'organization_id' => 50, 'name' => 'Checkout website', 'setup_stage' => 12, 'provisioning_status' => 'provisioned', 'deleted_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 602, 'server_id' => 502, 'organization_id' => 999, 'name' => 'Other organization website', 'setup_stage' => 12, 'provisioning_status' => 'provisioned', 'deleted_at' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::connection('deployer')->table('website_log_snapshots')->insert([
            ['id' => 701, 'website_id' => 601, 'type' => 'application', 'status' => 'refreshing', 'log' => 'website_log_content_secret', 'error' => null, 'refreshed_at' => null, 'created_at' => now()->subMinutes(2), 'updated_at' => now()->subMinute()],
            ['id' => 702, 'website_id' => 601, 'type' => 'access', 'status' => 'failed', 'log' => 'website_error_log_secret', 'error' => 'website_refresh_error_secret', 'refreshed_at' => null, 'created_at' => now()->subMinute(), 'updated_at' => now()],
            ['id' => 703, 'website_id' => 602, 'type' => 'access', 'status' => 'queued', 'log' => 'other_org_website_log_secret', 'error' => null, 'refreshed_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 704, 'website_id' => 601, 'type' => 'credentials', 'status' => 'failed', 'log' => 'unsupported_log_secret', 'error' => 'unsupported_log_secret', 'refreshed_at' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::connection('deployer')->table('server_log_snapshots')->insert([
            ['id' => 711, 'server_id' => 501, 'type' => 'caddy', 'status' => 'ready', 'log' => 'server_log_content_secret', 'error' => null, 'refreshed_at' => now()->subSeconds(10), 'created_at' => now()->subMinute(), 'updated_at' => now()],
            ['id' => 712, 'server_id' => 501, 'type' => 'php', 'status' => 'failed', 'log' => 'old_server_log_secret', 'error' => 'old_server_error_secret', 'refreshed_at' => null, 'created_at' => now()->subDays(31), 'updated_at' => now()->subDays(31)],
            ['id' => 713, 'server_id' => 502, 'type' => 'mysql', 'status' => 'failed', 'log' => 'other_org_server_log_secret', 'error' => 'other_org_server_error_secret', 'refreshed_at' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::connection('deployer')->table('website_health_checks')->insert([
            ['id' => 721, 'website_id' => 601, 'successful' => true, 'source' => 'manual', 'http_status' => 200, 'duration_ms' => 84, 'endpoint' => 'https://checkout-secret.test/health', 'error' => null, 'checked_at' => now()->subSeconds(20)],
            ['id' => 722, 'website_id' => 601, 'successful' => false, 'source' => 'automatic', 'http_status' => 503, 'duration_ms' => 900, 'endpoint' => 'https://private-endpoint-secret.test', 'error' => 'private_health_error_secret', 'checked_at' => now()->subSeconds(10)],
            ['id' => 723, 'website_id' => 601, 'successful' => false, 'source' => 'automatic', 'http_status' => 503, 'duration_ms' => 900, 'endpoint' => 'https://old-endpoint-secret.test', 'error' => 'old_health_error_secret', 'checked_at' => now()->subDays(31)],
            ['id' => 724, 'website_id' => 602, 'successful' => false, 'source' => 'automatic', 'http_status' => 503, 'duration_ms' => 900, 'endpoint' => 'https://other-org-endpoint-secret.test', 'error' => 'other_org_health_error_secret', 'checked_at' => now()],
        ]);
        DB::connection('deployer')->table('builds')->insert([
            ['id' => 103, 'environment_id' => 41, 'status' => 'succeeded', 'revision' => str_repeat('a', 40), 'release_name' => null, 'setup_stage' => 6, 'created_at' => now()->subMinutes(2), 'updated_at' => now()->subMinute(), 'started_at' => now()->subMinutes(2), 'finished_at' => now()->subMinute()],
            ['id' => 104, 'environment_id' => 41, 'status' => 'succeeded', 'revision' => str_repeat('b', 40), 'release_name' => null, 'setup_stage' => 6, 'created_at' => now()->subDays(32), 'updated_at' => now()->subDays(31), 'started_at' => now()->subDays(32), 'finished_at' => now()->subDays(31)],
        ]);
        DB::connection('deployer')->table('deployment_observations')->insert([
            ['id' => 731, 'build_id' => 100, 'website_id' => 601, 'server_id' => 501, 'revision' => str_repeat('c', 40), 'website_url' => 'https://observation-url-secret.test', 'health_check_path' => '/private-health', 'duration_minutes' => 5, 'status' => 'observing', 'successful_checks' => 3, 'attempts' => 4, 'last_http_status' => 503, 'last_duration_ms' => 1200, 'last_error' => 'observation_error_secret', 'started_at' => now()->subMinutes(3), 'last_checked_at' => now()->subMinutes(2), 'deadline_at' => now()->addMinutes(2), 'next_check_at' => now(), 'claim_token' => 'observation_claim_secret', 'lease_expires_at' => now()->subMinute(), 'completed_at' => null, 'created_at' => now()->subMinutes(3), 'updated_at' => now()],
            ['id' => 732, 'build_id' => 103, 'website_id' => 601, 'server_id' => 501, 'revision' => str_repeat('d', 40), 'website_url' => 'https://another-observation-url-secret.test', 'health_check_path' => '/health', 'duration_minutes' => 5, 'status' => 'healthy', 'successful_checks' => 4, 'attempts' => 4, 'last_http_status' => 200, 'last_duration_ms' => 80, 'last_error' => null, 'started_at' => now()->subMinutes(5), 'last_checked_at' => now()->subMinute(), 'deadline_at' => now()->addHour(), 'next_check_at' => null, 'claim_token' => null, 'lease_expires_at' => null, 'completed_at' => now()->subMinute(), 'created_at' => now()->subMinutes(5), 'updated_at' => now()],
            ['id' => 733, 'build_id' => 104, 'website_id' => 601, 'server_id' => 501, 'revision' => str_repeat('e', 40), 'website_url' => 'https://old-observation-url-secret.test', 'health_check_path' => '/health', 'duration_minutes' => 5, 'status' => 'failed', 'successful_checks' => 0, 'attempts' => 1, 'last_http_status' => 503, 'last_duration_ms' => 900, 'last_error' => 'old_observation_error_secret', 'started_at' => now()->subDays(32), 'last_checked_at' => now()->subDays(31), 'deadline_at' => now()->subDays(31), 'next_check_at' => null, 'claim_token' => null, 'lease_expires_at' => null, 'completed_at' => now()->subDays(31), 'created_at' => now()->subDays(32), 'updated_at' => now()->subDays(31)],
            ['id' => 734, 'build_id' => 102, 'website_id' => 602, 'server_id' => 502, 'revision' => str_repeat('f', 40), 'website_url' => 'https://cross-resource-observation-secret.test', 'health_check_path' => '/health', 'duration_minutes' => 5, 'status' => 'pending', 'successful_checks' => 0, 'attempts' => 0, 'last_http_status' => null, 'last_duration_ms' => null, 'last_error' => null, 'started_at' => null, 'last_checked_at' => null, 'deadline_at' => now()->addHour(), 'next_check_at' => now(), 'claim_token' => null, 'lease_expires_at' => null, 'completed_at' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $user = PlatformUser::query()->findOrFail($this->userId);
        $workspace = Workspace::query()->findOrFail($this->workspaceId);
        $project = CoreProject::query()->findOrFail($this->projectId);
        $snapshot = app(WorkspaceActivityProviderRegistry::class)
            ->get('deployer')
            ?->recentForWorkspace($user, $workspace, collect([$project]), 30);

        $this->assertNotNull($snapshot);
        $this->assertTrue($snapshot->available);
        $runsByKey = $snapshot->runs->keyBy('key');
        $this->assertTrue($runsByKey->has('deployer:website-log-refresh:701'));
        $this->assertTrue($runsByKey->has('deployer:website-log-refresh:702'));
        $this->assertTrue($runsByKey->has('deployer:server-log-refresh:711'));
        $this->assertTrue($runsByKey->has('deployer:website-health-check:721'));
        $this->assertTrue($runsByKey->has('deployer:website-health-check:722'));
        $this->assertTrue($runsByKey->has('deployer:deployment-observation:731'));
        $this->assertTrue($runsByKey->has('deployer:deployment-observation:732'));
        $this->assertFalse($runsByKey->has('deployer:website-log-refresh:703'));
        $this->assertFalse($runsByKey->has('deployer:website-log-refresh:704'));
        $this->assertFalse($runsByKey->has('deployer:server-log-refresh:712'));
        $this->assertFalse($runsByKey->has('deployer:server-log-refresh:713'));
        $this->assertFalse($runsByKey->has('deployer:website-health-check:723'));
        $this->assertFalse($runsByKey->has('deployer:website-health-check:724'));
        $this->assertFalse($runsByKey->has('deployer:deployment-observation:733'));
        $this->assertFalse($runsByKey->has('deployer:deployment-observation:734'));
        $this->assertSame('processing', $runsByKey['deployer:website-log-refresh:701']->steps[0]->state->value);
        $this->assertSame('failed', $runsByKey['deployer:website-log-refresh:702']->steps[0]->state->value);
        $this->assertSame('succeeded', $runsByKey['deployer:server-log-refresh:711']->steps[0]->state->value);
        $this->assertSame('succeeded', $runsByKey['deployer:website-health-check:721']->steps[0]->state->value);
        $this->assertSame('failed', $runsByKey['deployer:website-health-check:722']->steps[0]->state->value);
        $this->assertSame('unknown', $runsByKey['deployer:deployment-observation:731']->steps[0]->state->value);
        $this->assertSame('succeeded', $runsByKey['deployer:deployment-observation:732']->steps[0]->state->value);
        $this->assertSame(
            route('websites.show', ['website' => 601, 'organization_id' => 50]),
            $runsByKey['deployer:website-log-refresh:701']->steps[0]->resultUrl,
        );
        $this->assertSame(
            route('servers.show', ['server' => 501, 'organization_id' => 50]),
            $runsByKey['deployer:server-log-refresh:711']->steps[0]->resultUrl,
        );
        $this->assertSame(
            route('websites.health-checks.index', ['website' => 601, 'organization_id' => 50]),
            $runsByKey['deployer:website-health-check:722']->steps[0]->resultUrl,
        );
        $this->assertSame(
            route('builds.show', ['build' => 103, 'organization_id' => 50]),
            $runsByKey['deployer:deployment-observation:732']->steps[0]->resultUrl,
        );

        foreach ($snapshot->runs as $run) {
            foreach ($run->steps as $step) {
                foreach ([
                    'website_log_content_secret',
                    'website_error_log_secret',
                    'website_refresh_error_secret',
                    'other_org_website_log_secret',
                    'unsupported_log_secret',
                    'server_log_content_secret',
                    'old_server_log_secret',
                    'old_server_error_secret',
                    'other_org_server_log_secret',
                    'other_org_server_error_secret',
                    'checkout-secret.test',
                    'private-endpoint-secret.test',
                    'private_health_error_secret',
                    'old-endpoint-secret.test',
                    'old_health_error_secret',
                    'other-org-endpoint-secret.test',
                    'other_org_health_error_secret',
                    'observation-url-secret.test',
                    'observation_error_secret',
                    'observation_claim_secret',
                    'another-observation-url-secret.test',
                    'old-observation-url-secret.test',
                    'old_observation_error_secret',
                    'cross-resource-observation-secret.test',
                ] as $secret) {
                    $this->assertStringNotContainsString($secret, $step->detail);
                }
            }
        }
    }

    public function test_deployer_domain_activity_is_mapped_stale_aware_and_redacted(): void
    {
        config(['platform.products.deployer.auth_authority' => 'core']);
        $this->addIdentityMap('user', '17', 'user', $this->userId);
        $this->addIdentityMap('organization', '50', 'workspace', $this->workspaceId);
        $this->seedDeployerProjectAndBuilds();
        $this->createDeployerOperationalActivityTables();

        DB::connection('deployer')->table('environments')->where('id', 41)->update([
            'server_id' => 501,
            'website_id' => 601,
        ]);
        DB::connection('deployer')->table('environments')->insert([
            'id' => 43,
            'project_id' => 31,
            'server_id' => 502,
            'website_id' => 602,
            'name' => 'Cross-organization resources',
            'slug' => 'cross-organization-resources',
            'type' => 'staging',
        ]);
        DB::connection('core')->table('project_resources')->insert([
            'id' => (string) Str::ulid(),
            'project_id' => $this->projectId,
            'product' => 'deployer',
            'resource_type' => 'environment',
            'resource_id' => '43',
            'name' => 'Cross-organization environment',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('deployer')->table('servers')->insert([
            ['id' => 501, 'organization_id' => 50, 'type' => 'app', 'setup_stage' => 12, 'provisioning_status' => 'provisioned', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 502, 'organization_id' => 999, 'type' => 'app', 'setup_stage' => 12, 'provisioning_status' => 'provisioned', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::connection('deployer')->table('websites')->insert([
            ['id' => 601, 'server_id' => 501, 'organization_id' => 50, 'name' => 'Checkout website', 'setup_stage' => 12, 'provisioning_status' => 'provisioned', 'deleted_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 602, 'server_id' => 502, 'organization_id' => 999, 'name' => 'Other organization website', 'setup_stage' => 12, 'provisioning_status' => 'provisioned', 'deleted_at' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        Schema::connection('deployer')->create('website_domains', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('website_id');
            $table->unsignedBigInteger('dns_provider_id')->nullable();
            $table->string('hostname')->unique();
            $table->string('dns_status', 20)->default('pending');
            $table->string('ssl_status', 20)->default('pending');
            $table->timestamp('certificate_expires_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
        DB::connection('deployer')->table('website_domains')->insert([
            ['id' => 741, 'website_id' => 601, 'dns_provider_id' => 901, 'hostname' => 'mapped-dns-error-secret.test', 'dns_status' => 'error', 'ssl_status' => 'active', 'last_checked_at' => now()->subMinutes(2), 'last_error' => 'dns_error_secret', 'created_at' => now()->subMinutes(2), 'updated_at' => now()->subMinutes(2)],
            ['id' => 742, 'website_id' => 601, 'dns_provider_id' => 901, 'hostname' => 'mapped-tls-expiring-secret.test', 'dns_status' => 'active', 'ssl_status' => 'expiring', 'last_checked_at' => now()->subMinute(), 'last_error' => null, 'created_at' => now()->subMinute(), 'updated_at' => now()->subMinute()],
            ['id' => 743, 'website_id' => 602, 'dns_provider_id' => 902, 'hostname' => 'other-organization-domain-secret.test', 'dns_status' => 'error', 'ssl_status' => 'expired', 'last_checked_at' => now(), 'last_error' => 'other_org_dns_error_secret', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 744, 'website_id' => 601, 'dns_provider_id' => null, 'hostname' => 'manual-dns-setup-secret.test', 'dns_status' => 'pending', 'ssl_status' => 'active', 'last_checked_at' => now(), 'last_error' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 745, 'website_id' => 601, 'dns_provider_id' => 901, 'hostname' => 'stale-tls-check-secret.test', 'dns_status' => 'active', 'ssl_status' => 'pending', 'last_checked_at' => now()->subMinutes(30), 'last_error' => null, 'created_at' => now()->subMinutes(30), 'updated_at' => now()->subMinutes(30)],
            ['id' => 746, 'website_id' => 601, 'dns_provider_id' => 901, 'hostname' => 'healthy-domain-secret.test', 'dns_status' => 'active', 'ssl_status' => 'active', 'last_checked_at' => now(), 'last_error' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $user = PlatformUser::query()->findOrFail($this->userId);
        $workspace = Workspace::query()->findOrFail($this->workspaceId);
        $project = CoreProject::query()->findOrFail($this->projectId);
        $snapshot = app(WorkspaceActivityProviderRegistry::class)
            ->get('deployer')
            ?->recentForWorkspace($user, $workspace, collect([$project]), 30);

        $this->assertNotNull($snapshot);
        $this->assertTrue($snapshot->available);
        $runsByKey = $snapshot->runs->keyBy('key');
        $this->assertTrue($runsByKey->has('deployer:website-domain:741'));
        $this->assertTrue($runsByKey->has('deployer:website-domain:742'));
        $this->assertTrue($runsByKey->has('deployer:website-domain:744'));
        $this->assertTrue($runsByKey->has('deployer:website-domain:745'));
        $this->assertFalse($runsByKey->has('deployer:website-domain:743'));
        $this->assertFalse($runsByKey->has('deployer:website-domain:746'));
        $this->assertSame('failed', $runsByKey['deployer:website-domain:741']->steps[0]->state->value);
        $this->assertSame('blocked', $runsByKey['deployer:website-domain:742']->steps[1]->state->value);
        $this->assertSame('blocked', $runsByKey['deployer:website-domain:744']->steps[0]->state->value);
        $this->assertSame('unknown', $runsByKey['deployer:website-domain:745']->steps[1]->state->value);
        $this->assertSame(
            route('websites.show', ['website' => 601, 'organization_id' => 50]),
            $runsByKey['deployer:website-domain:742']->steps[0]->resultUrl,
        );

        foreach ($snapshot->runs as $run) {
            foreach ($run->steps as $step) {
                foreach ([
                    'mapped-dns-error-secret.test',
                    'mapped-tls-expiring-secret.test',
                    'other-organization-domain-secret.test',
                    'manual-dns-setup-secret.test',
                    'stale-tls-check-secret.test',
                    'healthy-domain-secret.test',
                    'dns_error_secret',
                    'other_org_dns_error_secret',
                ] as $secret) {
                    $this->assertStringNotContainsString($secret, $step->detail);
                }
            }
        }
    }

    public function test_deployer_configuration_delivery_activity_is_mapped_bounded_and_redacted(): void
    {
        config(['platform.products.deployer.auth_authority' => 'core']);
        $this->addIdentityMap('user', '17', 'user', $this->userId);
        $this->addIdentityMap('organization', '50', 'workspace', $this->workspaceId);
        $this->seedDeployerProjectAndBuilds();
        $this->createDeployerConfigurationActivityTables();

        DB::connection('deployer')->table('configuration_reviews')->insert([
            ['id' => 901, 'project_id' => 31, 'requested_by' => 17, 'document' => 'redacted', 'bindings' => '[]', 'summary' => '{}', 'expires_at' => now()->addHour(), 'applied_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['id' => 902, 'project_id' => 999, 'requested_by' => 17, 'document' => 'redacted', 'bindings' => '[]', 'summary' => '{}', 'expires_at' => now()->addHour(), 'applied_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['id' => 903, 'project_id' => 31, 'requested_by' => 17, 'document' => 'redacted', 'bindings' => '[]', 'summary' => '{}', 'expires_at' => now()->addHour(), 'applied_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['id' => 904, 'project_id' => 31, 'requested_by' => 17, 'document' => 'redacted', 'bindings' => '[]', 'summary' => '{}', 'expires_at' => now()->addHour(), 'applied_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['id' => 905, 'project_id' => 31, 'requested_by' => 17, 'document' => 'redacted', 'bindings' => '[]', 'summary' => '{}', 'expires_at' => now()->addHour(), 'applied_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::connection('deployer')->table('configuration_applications')->insert([
            ['id' => 911, 'configuration_review_id' => 901, 'status' => 'awaiting_dispatch', 'locally_applied_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['id' => 912, 'configuration_review_id' => 905, 'status' => 'needs_attention', 'locally_applied_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['id' => 913, 'configuration_review_id' => 902, 'status' => 'awaiting_dispatch', 'locally_applied_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['id' => 914, 'configuration_review_id' => 903, 'status' => 'remote_failed', 'locally_applied_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['id' => 915, 'configuration_review_id' => 904, 'status' => 'remote_failed', 'locally_applied_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::connection('deployer')->table('configuration_operations')->insert([
            ['id' => 921, 'configuration_application_id' => 911, 'environment_slug' => 'production', 'environment_id' => 41, 'build_id' => null, 'kind' => 'deploy', 'status' => 'pending', 'payload' => 'payload_secret_must_not_render', 'attempts' => 0, 'failure_code' => null, 'available_at' => now(), 'started_at' => null, 'completed_at' => null, 'created_at' => now()->subMinutes(5), 'updated_at' => now()],
            ['id' => 922, 'configuration_application_id' => 912, 'environment_slug' => 'production', 'environment_id' => 41, 'build_id' => null, 'kind' => 'deploy', 'status' => 'delivery_failed', 'payload' => 'payload_secret_must_not_render', 'attempts' => 2, 'failure_code' => 'provider_secret_must_not_render', 'available_at' => now(), 'started_at' => now()->subMinute(), 'completed_at' => null, 'created_at' => now()->subMinutes(2), 'updated_at' => now()],
            ['id' => 923, 'configuration_application_id' => 913, 'environment_slug' => 'production', 'environment_id' => 41, 'build_id' => null, 'kind' => 'deploy', 'status' => 'pending', 'payload' => 'cross_project_payload_secret', 'attempts' => 0, 'failure_code' => null, 'available_at' => now(), 'started_at' => null, 'completed_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 924, 'configuration_application_id' => 914, 'environment_slug' => 'production', 'environment_id' => 41, 'build_id' => 100, 'kind' => 'deploy', 'status' => 'failed', 'payload' => 'build_already_tracks_this_operation', 'attempts' => 1, 'failure_code' => null, 'available_at' => null, 'started_at' => now(), 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['id' => 925, 'configuration_application_id' => 915, 'environment_slug' => 'production', 'environment_id' => 41, 'build_id' => null, 'kind' => 'deploy', 'status' => 'failed', 'payload' => 'old_operation_payload', 'attempts' => 1, 'failure_code' => null, 'available_at' => null, 'started_at' => now()->subDays(31), 'completed_at' => now()->subDays(31), 'created_at' => now()->subDays(31), 'updated_at' => now()->subDays(31)],
        ]);

        $user = PlatformUser::query()->findOrFail($this->userId);
        $workspace = Workspace::query()->findOrFail($this->workspaceId);
        $project = CoreProject::query()->findOrFail($this->projectId);
        $snapshot = app(WorkspaceActivityProviderRegistry::class)
            ->get('deployer')
            ?->recentForWorkspace($user, $workspace, collect([$project]), 30);

        $this->assertNotNull($snapshot);
        $this->assertTrue($snapshot->available);
        $runs = $snapshot->runs->keyBy('key');
        $this->assertTrue($runs->has('deployer:configuration-operation:921'));
        $this->assertTrue($runs->has('deployer:configuration-operation:922'));
        $this->assertFalse($runs->has('deployer:configuration-operation:923'));
        $this->assertFalse($runs->has('deployer:configuration-operation:924'));
        $this->assertFalse($runs->has('deployer:configuration-operation:925'));
        $this->assertSame('pending', $runs['deployer:configuration-operation:921']->steps[0]->state->value);
        $this->assertSame('failed', $runs['deployer:configuration-operation:922']->steps[0]->state->value);
        $this->assertSame(
            route('projects.show', ['project' => 31, 'organization_id' => 50]),
            $runs['deployer:configuration-operation:921']->steps[0]->resultUrl,
        );

        foreach ($snapshot->runs as $run) {
            foreach ($run->steps as $step) {
                $this->assertStringNotContainsString('payload_secret_must_not_render', $step->detail);
                $this->assertStringNotContainsString('provider_secret_must_not_render', $step->detail);
                $this->assertStringNotContainsString('cross_project_payload_secret', $step->detail);
            }
        }
    }

    public function test_deployer_preview_initialization_and_cleanup_activity_is_project_mapped_bounded_and_redacted(): void
    {
        config(['platform.products.deployer.auth_authority' => 'core']);
        $this->addIdentityMap('user', '17', 'user', $this->userId);
        $this->addIdentityMap('organization', '50', 'workspace', $this->workspaceId);
        $this->seedDeployerProjectAndBuilds();
        DB::connection('deployer')->table('projects')->insert([
            'id' => 32,
            'organization_id' => 50,
        ]);
        DB::connection('core')->table('project_resources')
            ->where('project_id', $this->projectId)
            ->where('product', 'deployer')
            ->where('resource_type', 'environment')
            ->delete();
        $this->createDeployerPreviewActivityTables();

        $closedAt = now();
        DB::connection('deployer')->table('preview_deployments')->insert([
            ['id' => 601, 'project_id' => 31, 'pull_request_number' => 17, 'status' => 'provisioning', 'url' => 'https://preview-secret.example.test', 'source_branch' => 'private-branch-secret', 'revision' => 'private-revision-secret', 'initialization_status' => 'pending', 'initialization_attempts' => 0, 'initialization_completed_at' => null, 'last_activity_at' => now(), 'closed_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 602, 'project_id' => 31, 'pull_request_number' => 18, 'status' => 'failed', 'url' => 'https://failed-preview-secret.example.test', 'source_branch' => 'another-private-branch', 'revision' => 'another-private-revision', 'initialization_status' => 'failed', 'initialization_attempts' => 2, 'initialization_completed_at' => null, 'last_activity_at' => now()->subMinute(), 'closed_at' => null, 'created_at' => now()->subMinute(), 'updated_at' => now()],
            ['id' => 603, 'project_id' => 32, 'pull_request_number' => 19, 'status' => 'provisioning', 'url' => 'https://unmapped-preview.example.test', 'source_branch' => 'unmapped-private-branch', 'revision' => 'unmapped-private-revision', 'initialization_status' => 'running', 'initialization_attempts' => 1, 'initialization_completed_at' => null, 'last_activity_at' => now(), 'closed_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 604, 'project_id' => 31, 'pull_request_number' => 20, 'status' => 'closed', 'url' => 'https://old-preview.example.test', 'source_branch' => 'old-private-branch', 'revision' => 'old-private-revision', 'initialization_status' => 'succeeded', 'initialization_attempts' => 1, 'initialization_completed_at' => now()->subDays(31), 'last_activity_at' => now()->subDays(31), 'closed_at' => now()->subDays(31), 'created_at' => now()->subDays(32), 'updated_at' => now()->subDays(31)],
            ['id' => 605, 'project_id' => 31, 'pull_request_number' => 21, 'status' => 'closed', 'url' => 'https://recently-closed-preview.example.test', 'source_branch' => 'closed-private-branch', 'revision' => 'closed-private-revision', 'initialization_status' => 'not_configured', 'initialization_attempts' => 0, 'initialization_completed_at' => null, 'last_activity_at' => $closedAt, 'closed_at' => $closedAt, 'created_at' => now()->subHour(), 'updated_at' => $closedAt],
        ]);
        DB::connection('deployer')->table('preview_stack_cleanups')->insert([
            ['id' => 611, 'preview_deployment_id' => 601, 'status' => 'queued', 'attempts' => 0, 'process_manifest' => '{"secret":"cleanup_manifest_secret"}', 'resource_manifest' => '{"secret":"resource_manifest_secret"}', 'error' => null, 'started_at' => null, 'completed_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 612, 'preview_deployment_id' => 602, 'status' => 'failed', 'attempts' => 2, 'process_manifest' => '{"secret":"failed_cleanup_manifest_secret"}', 'resource_manifest' => '{"secret":"failed_resource_manifest_secret"}', 'error' => 'cleanup_provider_error_secret', 'started_at' => now()->subMinute(), 'completed_at' => null, 'created_at' => now()->subMinutes(2), 'updated_at' => now()],
            ['id' => 613, 'preview_deployment_id' => 603, 'status' => 'running', 'attempts' => 1, 'process_manifest' => '{}', 'resource_manifest' => '{}', 'error' => null, 'started_at' => now(), 'completed_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 614, 'preview_deployment_id' => 604, 'status' => 'succeeded', 'attempts' => 1, 'process_manifest' => '{}', 'resource_manifest' => '{}', 'error' => null, 'started_at' => now()->subDays(31), 'completed_at' => now()->subDays(31), 'created_at' => now()->subDays(32), 'updated_at' => now()->subDays(31)],
        ]);

        $user = PlatformUser::query()->findOrFail($this->userId);
        $workspace = Workspace::query()->findOrFail($this->workspaceId);
        $project = CoreProject::query()->findOrFail($this->projectId);
        $snapshot = app(WorkspaceActivityProviderRegistry::class)
            ->get('deployer')
            ?->recentForWorkspace($user, $workspace, collect([$project]), 30);

        $this->assertNotNull($snapshot);
        $this->assertTrue($snapshot->available);
        $runs = $snapshot->runs->keyBy('key');
        $this->assertFalse($runs->has('deployer:build:100'));
        foreach ([
            'deployer:preview:601',
            'deployer:preview-initialization:601',
            'deployer:preview:602',
            'deployer:preview-initialization:602',
            'deployer:preview:605',
            'deployer:preview-cleanup:611',
            'deployer:preview-cleanup:612',
        ] as $key) {
            $this->assertTrue($runs->has($key), "Expected mapped preview activity [{$key}].");
        }
        foreach ([
            'deployer:preview:603',
            'deployer:preview-initialization:603',
            'deployer:preview:604',
            'deployer:preview-initialization:604',
            'deployer:preview-cleanup:613',
            'deployer:preview-cleanup:614',
        ] as $key) {
            $this->assertFalse($runs->has($key), "Unexpected unmapped or stale preview activity [{$key}].");
        }

        $this->assertSame('pending', $runs['deployer:preview:601']->steps[0]->state->value);
        $this->assertSame('pending', $runs['deployer:preview-initialization:601']->steps[0]->state->value);
        $this->assertSame('failed', $runs['deployer:preview:602']->steps[0]->state->value);
        $this->assertSame('failed', $runs['deployer:preview-initialization:602']->steps[0]->state->value);
        $this->assertNull($runs['deployer:preview-initialization:602']->steps[0]->completedAt);
        $this->assertSame('discarded', $runs['deployer:preview:605']->steps[0]->state->value);
        $this->assertSame(
            $closedAt->toImmutable()->utc()->format('Y-m-d H:i:s'),
            $runs['deployer:preview:605']->steps[0]->completedAt?->format('Y-m-d H:i:s'),
        );
        $this->assertSame('pending', $runs['deployer:preview-cleanup:611']->steps[0]->state->value);
        $this->assertSame('failed', $runs['deployer:preview-cleanup:612']->steps[0]->state->value);
        $this->assertSame(
            route('projects.show', ['project' => 31, 'organization_id' => 50]).'#preview-environments',
            $runs['deployer:preview:601']->steps[0]->resultUrl,
        );

        foreach ($snapshot->runs as $run) {
            foreach ($run->steps as $step) {
                foreach ([
                    'preview-secret.example.test',
                    'private-branch-secret',
                    'private-revision-secret',
                    'cleanup_manifest_secret',
                    'resource_manifest_secret',
                    'cleanup_provider_error_secret',
                ] as $secret) {
                    $this->assertStringNotContainsString($secret, $step->detail);
                }
            }
        }
    }

    public function test_deployer_preview_activity_fails_closed_for_ambiguous_project_mappings(): void
    {
        config(['platform.products.deployer.auth_authority' => 'core']);
        $this->addIdentityMap('user', '17', 'user', $this->userId);
        $this->addIdentityMap('organization', '50', 'workspace', $this->workspaceId);
        $this->seedDeployerProjectAndBuilds();
        DB::connection('deployer')->table('projects')->insert([
            'id' => 32,
            'organization_id' => 50,
        ]);
        DB::connection('core')->table('project_resources')->insert([
            'id' => (string) Str::ulid(),
            'project_id' => $this->projectId,
            'product' => 'deployer',
            'resource_type' => 'project',
            'resource_id' => '32',
            'name' => 'Conflicting Deployer project',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createDeployerPreviewActivityTables();
        DB::connection('deployer')->table('preview_deployments')->insert([
            'id' => 601,
            'project_id' => 31,
            'pull_request_number' => 17,
            'status' => 'provisioning',
            'url' => 'https://preview-secret.example.test',
            'source_branch' => 'private-branch-secret',
            'revision' => 'private-revision-secret',
            'initialization_status' => 'pending',
            'initialization_attempts' => 0,
            'initialization_completed_at' => null,
            'last_activity_at' => now(),
            'closed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = PlatformUser::query()->findOrFail($this->userId);
        $workspace = Workspace::query()->findOrFail($this->workspaceId);
        $project = CoreProject::query()->findOrFail($this->projectId);
        $snapshot = app(WorkspaceActivityProviderRegistry::class)
            ->get('deployer')
            ?->recentForWorkspace($user, $workspace, collect([$project]), 30);

        $this->assertNotNull($snapshot);
        $this->assertTrue($snapshot->available);
        $this->assertFalse($snapshot->runs->contains(fn ($run): bool => str_starts_with($run->key, 'deployer:preview:')));
        $this->assertFalse($snapshot->runs->contains(fn ($run): bool => str_starts_with($run->key, 'deployer:preview-initialization:')));
    }

    public function test_monitor_credential_inventory_requires_a_mapped_admin_environment_and_omits_hashes(): void
    {
        $this->createMonitorActivityTables();
        $this->addIdentityMap('user', '17', 'user', $this->userId, 'monitor');
        $this->addIdentityMap('workspace', '81', 'workspace', $this->workspaceId, 'monitor');
        DB::connection('monitor')->table('users')->insert([
            'id' => 17,
            'name' => 'Taylor Owner',
            'email' => 'taylor@example.test',
            'password' => 'not-used-by-the-credential-provider',
        ]);
        DB::connection('monitor')->table('workspaces')->insert([
            'id' => 81,
            'owner_id' => 17,
            'name' => 'Northstar Monitor',
            'slug' => 'northstar-monitor',
            'plan' => 'free',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('monitor')->table('user_workspace')->insert([
            'workspace_id' => 81,
            'user_id' => 17,
            'role' => 'owner',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('monitor')->table('applications')->insert([
            'id' => 91,
            'workspace_id' => 81,
            'name' => 'Checkout monitor',
            'slug' => 'checkout-monitor',
            'framework' => 'Laravel',
            'framework_version' => null,
            'accent' => 'violet',
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);
        DB::connection('monitor')->table('environments')->insert([
            [
                'id' => 92,
                'application_id' => 91,
                'name' => 'Production',
                'slug' => 'production',
                'status' => 'active',
                'event_count' => 0,
                'last_seen_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ],
            [
                'id' => 93,
                'application_id' => 91,
                'name' => 'Unmapped staging',
                'slug' => 'staging',
                'status' => 'active',
                'event_count' => 0,
                'last_seen_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ],
        ]);
        DB::connection('core')->table('project_resources')
            ->where('project_id', $this->projectId)
            ->where('product', 'monitor')
            ->where('resource_type', 'environment')
            ->update(['resource_id' => '92', 'name' => 'Production telemetry']);
        DB::connection('monitor')->table('ingest_tokens')->insert([
            [
                'id' => 301,
                'environment_id' => 92,
                'created_by' => 17,
                'name' => 'Production ingest token',
                'token_hash' => hash('sha256', 'ingest_secret_must_not_render'),
                'prefix' => 'bcn_prod',
                'expires_at' => null,
                'revoked_at' => null,
                'last_used_at' => now()->subMinute(),
                'created_at' => now()->subDay(),
                'updated_at' => now(),
            ],
            [
                'id' => 302,
                'environment_id' => 93,
                'created_by' => 17,
                'name' => 'Unmapped ingest token',
                'token_hash' => hash('sha256', 'unmapped_ingest_secret_must_not_render'),
                'prefix' => 'bcn_stage',
                'expires_at' => null,
                'revoked_at' => null,
                'last_used_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::connection('monitor')->table('monitors')->insert([
            [
                'id' => 401,
                'environment_id' => 92,
                'name' => 'Queue depth',
                'type' => 'queue',
                'queue_token_hash' => hash('sha256', 'queue_secret_must_not_render'),
                'heartbeat_token_hash' => null,
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ],
            [
                'id' => 402,
                'environment_id' => 92,
                'name' => 'Worker heartbeat',
                'type' => 'heartbeat',
                'queue_token_hash' => null,
                'heartbeat_token_hash' => hash('sha256', 'heartbeat_secret_must_not_render'),
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ],
        ]);

        $user = PlatformUser::query()->findOrFail($this->userId);
        $workspace = Workspace::query()->findOrFail($this->workspaceId);
        $project = CoreProject::query()->findOrFail($this->projectId);
        $snapshot = app(MonitorWorkspaceCredentialProvider::class)
            ->credentialsForWorkspace($user, $workspace, collect([$project]), 100);

        $this->assertTrue($snapshot->available);
        $this->assertCount(3, $snapshot->credentials);
        $this->assertTrue($snapshot->credentials->contains(fn (WorkspaceCredential $credential): bool => $credential->key === 'monitor:ingest-token:301'));
        $this->assertTrue($snapshot->credentials->contains(fn (WorkspaceCredential $credential): bool => $credential->key === 'monitor:queue-key:401'));
        $this->assertTrue($snapshot->credentials->contains(fn (WorkspaceCredential $credential): bool => $credential->key === 'monitor:heartbeat-key:402'));
        $this->assertFalse($snapshot->credentials->contains(fn (WorkspaceCredential $credential): bool => str_contains($credential->name, 'Unmapped')));
        $serialized = json_encode($snapshot->credentials, JSON_THROW_ON_ERROR);
        foreach ([
            'ingest_secret_must_not_render',
            'unmapped_ingest_secret_must_not_render',
            'queue_secret_must_not_render',
            'heartbeat_secret_must_not_render',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }

        DB::connection('monitor')->table('user_workspace')->where('workspace_id', 81)->update(['role' => 'member']);
        $memberSnapshot = app(MonitorWorkspaceCredentialProvider::class)
            ->credentialsForWorkspace($user, $workspace, collect([$project]), 100);
        $this->assertCount(0, $memberSnapshot->credentials);
    }

    public function test_monitor_telemetry_activity_requires_mapped_environment_and_matching_source_workspace(): void
    {
        $this->createMonitorActivityTables();
        $this->createMonitorAlertDeliveryTables();
        $this->addIdentityMap('user', '17', 'user', $this->userId, 'monitor');
        $this->addIdentityMap('workspace', '81', 'workspace', $this->workspaceId, 'monitor');
        DB::connection('monitor')->table('users')->insert([
            'id' => 17,
            'name' => 'Taylor Owner',
            'email' => 'taylor@example.test',
            'password' => 'not-used-by-the-activity-provider',
        ]);
        DB::connection('monitor')->table('workspaces')->insert([
            'id' => 81,
            'owner_id' => 17,
            'name' => 'Northstar Monitor',
            'slug' => 'northstar-monitor',
            'plan' => 'free',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('monitor')->table('user_workspace')->insert([
            'workspace_id' => 81,
            'user_id' => 17,
            'role' => 'owner',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('monitor')->table('applications')->insert([
            'id' => 91,
            'workspace_id' => 81,
            'name' => 'Checkout monitor',
            'slug' => 'checkout-monitor',
            'framework' => 'Laravel',
            'framework_version' => null,
            'accent' => 'violet',
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);
        DB::connection('monitor')->table('environments')->insert([
            'id' => 92,
            'application_id' => 91,
            'name' => 'Production',
            'slug' => 'production',
            'status' => 'active',
            'event_count' => 15,
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);
        $canonicalEnvironmentId = (string) Str::ulid();
        DB::connection('core')->table('project_environments')->insert([
            'id' => $canonicalEnvironmentId,
            'project_id' => $this->projectId,
            'created_by_user_id' => $this->userId,
            'name' => 'Core Production',
            'slug' => 'production',
            'environment_type' => 'production',
            'status' => 'active',
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('project_resources')->insert([
            'id' => (string) Str::ulid(),
            'project_id' => $this->projectId,
            'environment_id' => $canonicalEnvironmentId,
            'product' => 'monitor',
            'resource_type' => 'environment',
            'resource_id' => '92',
            'name' => 'Production telemetry',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('monitor')->table('ingest_receipts')->insert([
            [
                'id' => (string) Str::ulid(),
                'workspace_id' => 81,
                'environment_id' => 92,
                'receipt_key' => hash('sha256', 'authorized-receipt'),
                'payload_fingerprint' => hash('sha256', 'payload'),
                'source' => 'http',
                'status' => 'failed',
                'event_count' => 8,
                'accepted_count' => 3,
                'attempt_count' => 1,
                'received_at' => now()->subMinute(),
                'last_received_at' => now()->subMinute(),
                'processed_at' => null,
                'processing_started_at' => now()->subSeconds(30),
                'failed_at' => now(),
                'last_error_code' => 'sensitive_provider_failure_code',
                'created_at' => now()->subMinute(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::ulid(),
                'workspace_id' => 999,
                'environment_id' => 92,
                'receipt_key' => hash('sha256', 'mismatched-workspace-receipt'),
                'payload_fingerprint' => hash('sha256', 'another-payload'),
                'source' => 'http',
                'status' => 'completed',
                'event_count' => 1,
                'accepted_count' => 1,
                'attempt_count' => 1,
                'received_at' => now(),
                'last_received_at' => now(),
                'processed_at' => now(),
                'processing_started_at' => now(),
                'failed_at' => null,
                'last_error_code' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::connection('monitor')->table('monitors')->insert([
            [
                'id' => 93,
                'environment_id' => 92,
                'name' => 'Checkout uptime',
                'type' => 'http',
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ],
            [
                'id' => 94,
                'environment_id' => 92,
                'name' => 'Checkout heartbeat',
                'type' => 'heartbeat',
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ],
        ]);
        $queuedCheckId = (string) Str::ulid();
        $failedCheckId = (string) Str::ulid();
        $successfulCheckId = (string) Str::ulid();
        DB::connection('monitor')->table('monitor_checks')->insert([
            [
                'id' => $queuedCheckId,
                'monitor_id' => 93,
                'status' => 'queued',
                'outcome' => null,
                'reason' => 'queued_check_secret',
                'scheduled_at' => now(),
                'started_at' => null,
                'finished_at' => null,
                'processing_token' => 'check_processing_token_secret',
                'queue_job_uuid' => (string) Str::uuid(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $failedCheckId,
                'monitor_id' => 93,
                'status' => 'completed',
                'outcome' => 'down',
                'reason' => 'sensitive_provider_failure_reason',
                'scheduled_at' => now()->subMinutes(2),
                'started_at' => now()->subMinutes(2),
                'finished_at' => now()->subMinute(),
                'processing_token' => null,
                'queue_job_uuid' => null,
                'created_at' => now()->subMinutes(2),
                'updated_at' => now()->subMinute(),
            ],
            [
                'id' => $successfulCheckId,
                'monitor_id' => 93,
                'status' => 'completed',
                'outcome' => 'up',
                'reason' => null,
                'scheduled_at' => now()->subMinutes(3),
                'started_at' => now()->subMinutes(3),
                'finished_at' => now()->subMinutes(2),
                'processing_token' => null,
                'queue_job_uuid' => null,
                'created_at' => now()->subMinutes(3),
                'updated_at' => now()->subMinutes(2),
            ],
        ]);
        DB::connection('monitor')->table('heartbeat_runs')->insert([
            'id' => 801,
            'monitor_id' => 94,
            'run_id' => (string) Str::uuid(),
            'status' => 'timed_out',
            'terminal_signal' => null,
            'started_at' => now()->subMinutes(8),
            'finished_at' => null,
            'deadline_at' => now()->subMinute(),
            'created_at' => now()->subMinutes(8),
            'updated_at' => now()->subMinute(),
        ]);
        DB::connection('monitor')->table('alert_rules')->insert([
            ['id' => 95, 'environment_id' => 92, 'deleted_at' => null],
            ['id' => 96, 'environment_id' => 999, 'deleted_at' => null],
        ]);
        DB::connection('monitor')->table('incidents')->insert([
            ['id' => 96, 'alert_rule_id' => 95, 'monitor_id' => null, 'title' => 'incident_title_secret', 'status' => 'open', 'opened_at' => now()->subMinutes(4), 'acknowledged_at' => null, 'resolved_at' => null, 'closure_reason' => null, 'created_at' => now()->subMinutes(4), 'updated_at' => now()->subMinutes(4)],
            ['id' => 97, 'alert_rule_id' => null, 'monitor_id' => 93, 'title' => 'another_incident_title_secret', 'status' => 'acknowledged', 'opened_at' => now()->subMinutes(3), 'acknowledged_at' => now()->subMinute(), 'resolved_at' => null, 'closure_reason' => null, 'created_at' => now()->subMinutes(3), 'updated_at' => now()->subMinute()],
            ['id' => 98, 'alert_rule_id' => 96, 'monitor_id' => null, 'title' => 'unmapped_incident_secret', 'status' => 'open', 'opened_at' => now(), 'acknowledged_at' => null, 'resolved_at' => null, 'closure_reason' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 99, 'alert_rule_id' => 95, 'monitor_id' => null, 'title' => 'recovered_incident_title_secret', 'status' => 'resolved', 'opened_at' => now()->subMinutes(10), 'acknowledged_at' => null, 'resolved_at' => now()->subMinute(), 'closure_reason' => 'recovered', 'created_at' => now()->subMinutes(10), 'updated_at' => now()->subMinute()],
        ]);
        DB::connection('monitor')->table('alert_destinations')->insert([
            ['id' => 101, 'workspace_id' => 81, 'type' => 'webhook', 'endpoint_url' => 'https://destination-secret.example', 'signing_secret' => 'destination_signing_secret'],
            ['id' => 102, 'workspace_id' => 81, 'type' => 'email', 'endpoint_url' => null, 'signing_secret' => null],
            ['id' => 103, 'workspace_id' => 81, 'type' => 'webhook', 'endpoint_url' => null, 'signing_secret' => null],
            ['id' => 104, 'workspace_id' => 999, 'type' => 'webhook', 'endpoint_url' => 'https://other-workspace-secret.example', 'signing_secret' => null],
        ]);
        $failedDeliveryId = (string) Str::ulid();
        $queuedDeliveryId = (string) Str::ulid();
        $acceptedDeliveryId = (string) Str::ulid();
        $oldDeliveryId = (string) Str::ulid();
        $otherWorkspaceDeliveryId = (string) Str::ulid();
        $wrongDestinationDeliveryId = (string) Str::ulid();
        $unmappedEnvironmentDeliveryId = (string) Str::ulid();
        DB::connection('monitor')->table('alert_deliveries')->insert([
            ['id' => $failedDeliveryId, 'workspace_id' => 81, 'alert_destination_id' => 101, 'incident_id' => 96, 'event' => 'opened', 'target_revision' => 1, 'payload' => 'alert_payload_secret', 'status' => 'failed', 'generation' => 0, 'attempt_count' => 1, 'cycle_attempts' => 1, 'queue_job_uuid' => (string) Str::uuid(), 'processing_token' => null, 'next_attempt_at' => null, 'accepted_at' => null, 'failed_at' => now()->subMinute(), 'last_error_code' => 'provider_error_secret', 'http_status' => 500, 'created_at' => now()->subMinutes(2), 'updated_at' => now()->subMinute()],
            ['id' => $queuedDeliveryId, 'workspace_id' => 81, 'alert_destination_id' => 102, 'incident_id' => 97, 'event' => 'opened', 'target_revision' => 1, 'payload' => 'alert_payload_secret', 'status' => 'queued', 'generation' => 0, 'attempt_count' => 0, 'cycle_attempts' => 0, 'queue_job_uuid' => null, 'processing_token' => 'delivery_processing_secret', 'next_attempt_at' => now(), 'accepted_at' => null, 'failed_at' => null, 'last_error_code' => null, 'http_status' => null, 'created_at' => now()->subMinute(), 'updated_at' => now()],
            ['id' => $acceptedDeliveryId, 'workspace_id' => 81, 'alert_destination_id' => 103, 'incident_id' => 96, 'event' => 'recovered', 'target_revision' => 1, 'payload' => 'alert_payload_secret', 'status' => 'accepted', 'generation' => 0, 'attempt_count' => 1, 'cycle_attempts' => 1, 'queue_job_uuid' => null, 'processing_token' => null, 'next_attempt_at' => null, 'accepted_at' => now()->subSeconds(30), 'failed_at' => null, 'last_error_code' => null, 'http_status' => 202, 'created_at' => now()->subMinute(), 'updated_at' => now()->subSeconds(30)],
            ['id' => $otherWorkspaceDeliveryId, 'workspace_id' => 999, 'alert_destination_id' => 104, 'incident_id' => 96, 'event' => 'recovered', 'target_revision' => 1, 'payload' => 'other_workspace_secret', 'status' => 'failed', 'generation' => 0, 'attempt_count' => 1, 'cycle_attempts' => 1, 'queue_job_uuid' => null, 'processing_token' => null, 'next_attempt_at' => null, 'accepted_at' => null, 'failed_at' => now(), 'last_error_code' => null, 'http_status' => 500, 'created_at' => now(), 'updated_at' => now()],
            ['id' => $wrongDestinationDeliveryId, 'workspace_id' => 81, 'alert_destination_id' => 104, 'incident_id' => 96, 'event' => 'opened', 'target_revision' => 1, 'payload' => 'wrong_destination_secret', 'status' => 'failed', 'generation' => 0, 'attempt_count' => 1, 'cycle_attempts' => 1, 'queue_job_uuid' => null, 'processing_token' => null, 'next_attempt_at' => null, 'accepted_at' => null, 'failed_at' => now(), 'last_error_code' => null, 'http_status' => 500, 'created_at' => now(), 'updated_at' => now()],
            ['id' => $oldDeliveryId, 'workspace_id' => 81, 'alert_destination_id' => 103, 'incident_id' => 96, 'event' => 'opened', 'target_revision' => 1, 'payload' => 'old_delivery_secret', 'status' => 'accepted', 'generation' => 0, 'attempt_count' => 1, 'cycle_attempts' => 1, 'queue_job_uuid' => null, 'processing_token' => null, 'next_attempt_at' => null, 'accepted_at' => now()->subDays(31), 'failed_at' => null, 'last_error_code' => null, 'http_status' => 202, 'created_at' => now()->subDays(31), 'updated_at' => now()->subDays(31)],
            ['id' => $unmappedEnvironmentDeliveryId, 'workspace_id' => 81, 'alert_destination_id' => 101, 'incident_id' => 98, 'event' => 'opened', 'target_revision' => 1, 'payload' => 'unmapped_environment_secret', 'status' => 'failed', 'generation' => 0, 'attempt_count' => 1, 'cycle_attempts' => 1, 'queue_job_uuid' => null, 'processing_token' => null, 'next_attempt_at' => null, 'accepted_at' => null, 'failed_at' => now(), 'last_error_code' => null, 'http_status' => 500, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $user = PlatformUser::query()->findOrFail($this->userId);
        $workspace = Workspace::query()->findOrFail($this->workspaceId);
        $project = CoreProject::query()->findOrFail($this->projectId);
        $snapshot = app(MonitorWorkspaceActivityProvider::class)
            ->recentForWorkspace($user, $workspace, collect([$project]), 30);

        $this->assertTrue($snapshot->available);
        $this->assertCount(10, $snapshot->runs);
        $runs = $snapshot->runs->keyBy('key');
        $receiptRun = $runs->first(fn ($run): bool => str_starts_with($run->key, 'monitor:telemetry-receipt:'));
        $queuedRun = $runs->get('monitor:check:'.$queuedCheckId);
        $failedRun = $runs->get('monitor:check:'.$failedCheckId);
        $heartbeatRun = $runs->get('monitor:heartbeat-run:801');
        $failedDelivery = $runs->get('monitor:alert-delivery:'.$failedDeliveryId);
        $queuedDelivery = $runs->get('monitor:alert-delivery:'.$queuedDeliveryId);
        $acceptedDelivery = $runs->get('monitor:alert-delivery:'.$acceptedDeliveryId);
        $openIncident = $runs->first(fn ($run): bool => str_starts_with($run->key, 'monitor:incident:96:open:'));
        $acknowledgedIncident = $runs->first(fn ($run): bool => str_starts_with($run->key, 'monitor:incident:97:acknowledged:'));
        $recoveredIncident = $runs->first(fn ($run): bool => str_starts_with($run->key, 'monitor:incident:99:resolved:'));

        $this->assertSame('Telemetry processing', $receiptRun->title);
        $this->assertSame('monitor', $receiptRun->steps[0]->product);
        $this->assertSame('failed', $receiptRun->steps[0]->state->value);
        $this->assertStringContainsString('Telemetry processing failed', $receiptRun->steps[0]->detail);
        $this->assertStringNotContainsString('sensitive_provider_failure_code', $receiptRun->steps[0]->detail);
        $this->assertSame('pending', $queuedRun->steps[0]->state->value);
        $this->assertSame('failed', $failedRun->steps[0]->state->value);
        $this->assertStringNotContainsString('sensitive_provider_failure_reason', $failedRun->steps[0]->detail);
        $this->assertStringNotContainsString('check_processing_token_secret', $queuedRun->steps[0]->detail);
        $this->assertSame('failed', $heartbeatRun->steps[0]->state->value);
        $this->assertStringContainsString('/monitors/93?workspace_id=81', $failedRun->steps[0]->resultUrl);
        $this->assertSame('failed', $failedDelivery->steps[0]->state->value);
        $this->assertSame('pending', $queuedDelivery->steps[0]->state->value);
        $this->assertSame('delivered', $acceptedDelivery->steps[0]->state->value);
        $this->assertSame('failed', $openIncident->steps[0]->state->value);
        $this->assertSame('blocked', $acknowledgedIncident->steps[0]->state->value);
        $this->assertSame('succeeded', $recoveredIncident->steps[0]->state->value);
        $this->assertStringContainsString('recovery', strtolower($recoveredIncident->steps[0]->detail));
        $this->assertSame($canonicalEnvironmentId, $openIncident->steps[0]->environmentId);
        $this->assertSame('Core Production', $openIncident->steps[0]->environmentName);
        $this->assertSame($this->projectId, $openIncident->projectId);
        $deliveryHistory = app(MonitorWorkspaceActivityProvider::class)
            ->recentWebhookDeliveriesForWorkspace($user, $workspace, collect([$project]), 30);

        $this->assertTrue($deliveryHistory->available);
        $this->assertCount(2, $deliveryHistory->deliveries);
        $this->assertSame(
            ['accepted', 'failed'],
            $deliveryHistory->deliveries->pluck('status')->sort()->values()->all(),
        );
        $this->assertSame([1, 1], $deliveryHistory->deliveries->pluck('attemptCount')->sort()->values()->all());
        $this->assertTrue($deliveryHistory->deliveries->every(fn ($delivery): bool => $delivery->product === 'monitor'
            && ! str_contains($delivery->title, 'secret')
            && ! str_contains((string) $delivery->resultUrl, 'destination-secret')));
        $this->assertStringContainsString('/incidents/96?workspace_id=81', $openIncident->steps[0]->resultUrl);
        $this->assertStringNotContainsString('incident_title_secret', $openIncident->steps[0]->title.$openIncident->steps[0]->detail);
        $this->assertStringNotContainsString('another_incident_title_secret', $acknowledgedIncident->steps[0]->title.$acknowledgedIncident->steps[0]->detail);
        $this->assertStringNotContainsString('recovered_incident_title_secret', $recoveredIncident->steps[0]->title.$recoveredIncident->steps[0]->detail);
        $this->assertSame(
            Route::has('monitor.alert-deliveries.show')
                ? route('monitor.alert-deliveries.show', ['alertDelivery' => $failedDeliveryId, 'workspace_id' => 81])
                : (Route::has('monitor.incidents.show')
                    ? route('monitor.incidents.show', ['incident' => 96, 'workspace_id' => 81])
                    : null),
            $failedDelivery->steps[0]->resultUrl,
        );
        $this->assertArrayNotHasKey('monitor:check:'.$successfulCheckId, $runs->all());
        $this->assertArrayNotHasKey('monitor:alert-delivery:'.$oldDeliveryId, $runs->all());
        $this->assertArrayNotHasKey('monitor:alert-delivery:'.$otherWorkspaceDeliveryId, $runs->all());
        $this->assertArrayNotHasKey('monitor:alert-delivery:'.$wrongDestinationDeliveryId, $runs->all());
        $this->assertArrayNotHasKey('monitor:alert-delivery:'.$unmappedEnvironmentDeliveryId, $runs->all());

        foreach ($snapshot->runs as $run) {
            foreach ($run->steps as $step) {
                foreach ([
                    'alert_payload_secret',
                    'provider_error_secret',
                    'delivery_processing_secret',
                    'other_workspace_secret',
                    'wrong_destination_secret',
                    'old_delivery_secret',
                    'unmapped_environment_secret',
                    'https://destination-secret.example',
                    'https://other-workspace-secret.example',
                    'destination_signing_secret',
                ] as $secret) {
                    $this->assertStringNotContainsString($secret, $step->detail);
                }
            }
        }

        DB::connection('monitor')->table('user_workspace')
            ->where('workspace_id', 81)
            ->where('user_id', 17)
            ->update(['role' => 'member']);
        $memberSnapshot = app(MonitorWorkspaceActivityProvider::class)
            ->recentForWorkspace($user, $workspace, collect([$project]), 30);
        $memberDelivery = $memberSnapshot->runs->firstWhere('key', 'monitor:alert-delivery:'.$failedDeliveryId);
        $this->assertSame(
            Route::has('monitor.incidents.show')
                ? route('monitor.incidents.show', ['incident' => 96, 'workspace_id' => 81])
                : null,
            $memberDelivery->steps[0]->resultUrl,
        );

        $otherWorkspaceId = (string) Str::ulid();
        $otherMembershipId = (string) Str::ulid();
        DB::connection('core')->table('workspaces')->insert([
            'id' => $otherWorkspaceId,
            'owner_user_id' => $this->userId,
            'name' => 'Other Monitor workspace',
            'slug' => 'other-monitor-workspace',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('workspace_memberships')->insert([
            'id' => $otherMembershipId,
            'workspace_id' => $otherWorkspaceId,
            'user_id' => $this->userId,
            'role' => 'owner',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('workspace_product_access')->insert([
            'id' => (string) Str::ulid(),
            'membership_id' => $otherMembershipId,
            'product' => 'monitor',
            'role' => 'owner',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('legacy_identity_maps')
            ->where('source_product', 'monitor')
            ->where('source_entity', 'workspace')
            ->where('source_id', '81')
            ->update(['canonical_id' => $otherWorkspaceId]);

        $mismatchedWorkspaceSnapshot = app(MonitorWorkspaceActivityProvider::class)
            ->recentForWorkspace($user, $workspace, collect([$project]), 30);

        $this->assertTrue($mismatchedWorkspaceSnapshot->available);
        $this->assertCount(0, $mismatchedWorkspaceSnapshot->runs);
    }

    private function createMonitorActivityTables(): void
    {
        foreach ([
            'alert_delivery_attempts',
            'alert_deliveries',
            'alert_destinations',
            'incidents',
            'alert_rules',
            'heartbeat_runs',
            'monitor_checks',
            'monitors',
            'ingest_tokens',
            'ingest_receipts',
            'environments',
            'applications',
            'user_workspace',
            'workspaces',
            'users',
        ] as $table) {
            Schema::connection('monitor')->dropIfExists($table);
        }

        Schema::connection('monitor')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('password');
        });
        Schema::connection('monitor')->create('workspaces', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('owner_id');
            $table->string('name');
            $table->string('slug');
            $table->string('plan');
            $table->timestamps();
        });
        Schema::connection('monitor')->create('user_workspace', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role');
            $table->timestamps();
        });
        Schema::connection('monitor')->create('applications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->string('name');
            $table->string('slug');
            $table->string('framework');
            $table->string('framework_version')->nullable();
            $table->string('accent');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::connection('monitor')->create('environments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('application_id');
            $table->string('name');
            $table->string('slug');
            $table->string('status');
            $table->unsignedBigInteger('event_count')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::connection('monitor')->create('ingest_receipts', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('environment_id')->nullable();
            $table->string('receipt_key');
            $table->string('payload_fingerprint');
            $table->string('source');
            $table->string('status');
            $table->unsignedInteger('event_count');
            $table->unsignedInteger('accepted_count')->default(0);
            $table->unsignedBigInteger('attempt_count')->default(1);
            $table->timestamp('received_at')->nullable();
            $table->timestamp('last_received_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->timestamps();
        });
        Schema::connection('monitor')->create('monitors', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('environment_id');
            $table->string('name');
            $table->string('type');
            $table->char('queue_token_hash', 64)->nullable();
            $table->char('heartbeat_token_hash', 64)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::connection('monitor')->create('ingest_tokens', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('environment_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('name', 120);
            $table->char('token_hash', 64)->unique();
            $table->string('prefix', 16);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('monitor')->create('monitor_checks', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->unsignedBigInteger('monitor_id');
            $table->string('status');
            $table->string('outcome')->nullable();
            $table->string('reason')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->uuid('processing_token')->nullable();
            $table->uuid('queue_job_uuid')->nullable();
            $table->timestamps();
        });
        Schema::connection('monitor')->create('heartbeat_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('monitor_id');
            $table->uuid('run_id');
            $table->string('status');
            $table->string('terminal_signal')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('deadline_at')->nullable();
            $table->timestamps();
        });
    }

    private function createMonitorAlertDeliveryTables(): void
    {
        Schema::connection('monitor')->create('alert_rules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('environment_id');
            $table->softDeletes();
        });
        Schema::connection('monitor')->create('incidents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('alert_rule_id')->nullable();
            $table->unsignedBigInteger('monitor_id')->nullable();
            $table->string('title')->nullable();
            $table->string('status')->default('open');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('closure_reason')->nullable();
            $table->timestamps();
        });
        Schema::connection('monitor')->create('alert_destinations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->string('type')->nullable();
            $table->text('endpoint_url')->nullable();
            $table->text('signing_secret')->nullable();
        });
        Schema::connection('monitor')->create('alert_deliveries', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('alert_destination_id');
            $table->unsignedBigInteger('incident_id')->nullable();
            $table->string('event');
            $table->unsignedInteger('target_revision');
            $table->text('payload');
            $table->string('status');
            $table->unsignedInteger('generation')->default(0);
            $table->unsignedInteger('attempt_count')->default(0);
            $table->unsignedTinyInteger('cycle_attempts')->default(0);
            $table->uuid('queue_job_uuid')->nullable();
            $table->uuid('processing_token')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->timestamps();
            $table->unique(['incident_id', 'alert_destination_id', 'event']);
        });
    }

    private function createDeployerBackupActivityTables(): void
    {
        Schema::connection('deployer')->create('website_backups', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('website_id');
            $table->string('status');
            $table->string('snapshot_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('deployer')->create('backup_restores', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('website_backup_id');
            $table->string('status');
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('deployer')->create('backup_restore_verifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('website_backup_id');
            $table->string('status');
            $table->string('snapshot_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    private function createDeployerDatabaseOperationActivityTables(): void
    {
        Schema::connection('deployer')->create('environment_resources', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('environment_id');
            $table->string('type');
        });
        Schema::connection('deployer')->create('database_operation_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('environment_resource_id');
            $table->string('operation', 32);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('status', 24)->default('queued');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamps();
        });
    }

    private function createDeployerOperationalActivityTables(): void
    {
        Schema::connection('deployer')->create('scheduled_tasks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('environment_id');
            $table->string('name');
            $table->timestamps();
        });
        Schema::connection('deployer')->create('scheduled_task_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('scheduled_task_id');
            $table->string('status');
            $table->text('output')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('deployer')->create('environment_resources', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('environment_id');
        });
        Schema::connection('deployer')->create('database_clones', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('source_resource_id');
            $table->unsignedBigInteger('target_resource_id');
            $table->string('status');
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('deployer')->create('server_command_executions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('server_id');
            $table->unsignedBigInteger('user_id');
            $table->text('command');
            $table->string('status');
            $table->text('output')->nullable();
            $table->integer('exit_code')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('deployer')->create('server_diagnostic_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('server_id')->unique();
            $table->string('status');
            $table->json('checks')->nullable();
            $table->string('failure_stage')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('attempt')->default(1);
            $table->uuid('attempt_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('deployer')->create('load_balancers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('environment_id');
            $table->unsignedBigInteger('server_id');
            $table->string('hostname');
            $table->string('status');
            $table->text('last_error')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('deployer')->create('deployment_observations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('build_id')->unique();
            $table->unsignedBigInteger('website_id');
            $table->unsignedBigInteger('server_id')->nullable();
            $table->string('revision', 64);
            $table->string('website_url');
            $table->string('health_check_path');
            $table->unsignedSmallInteger('duration_minutes');
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('successful_checks')->default(0);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('last_http_status')->nullable();
            $table->unsignedInteger('last_duration_ms')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('deadline_at');
            $table->timestamp('next_check_at')->nullable();
            $table->uuid('claim_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['website_id', 'status']);
            $table->index(['status', 'next_check_at']);
        });
    }

    private function createDeployerLogSnapshotTables(): void
    {
        Schema::connection('deployer')->create('website_log_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('website_id');
            $table->string('type');
            $table->string('status');
            $table->longText('log')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('refreshed_at')->nullable();
            $table->timestamps();
            $table->unique(['website_id', 'type']);
        });
        Schema::connection('deployer')->create('server_log_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('server_id');
            $table->string('type');
            $table->string('status');
            $table->longText('log')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('refreshed_at')->nullable();
            $table->timestamps();
            $table->unique(['server_id', 'type']);
        });
        Schema::connection('deployer')->create('website_health_checks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('website_id');
            $table->boolean('successful');
            $table->string('source', 16);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('endpoint', 512);
            $table->string('error', 500)->nullable();
            $table->timestamp('checked_at');
        });
    }

    private function createDeployerRepositoryWebhookActivityTable(): void
    {
        Schema::connection('deployer')->create('repository_webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('repository_id');
            $table->string('delivery_id');
            $table->string('revision', 64)->nullable();
            $table->text('commit_message')->nullable();
            $table->unsignedBigInteger('build_id')->nullable();
            $table->json('changed_paths')->nullable();
            $table->string('status');
            $table->timestamps();
        });
    }

    private function createDeployerPreviewActivityTables(): void
    {
        Schema::connection('deployer')->create('preview_deployments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedInteger('pull_request_number');
            $table->string('status');
            $table->string('url');
            $table->string('source_branch');
            $table->string('revision', 64);
            $table->string('initialization_status', 24)->default('not_configured');
            $table->unsignedSmallInteger('initialization_attempts')->default(0);
            $table->timestamp('initialization_completed_at')->nullable();
            $table->timestamp('last_activity_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('deployer')->create('preview_stack_cleanups', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('preview_deployment_id');
            $table->string('status');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->json('process_manifest')->nullable();
            $table->json('resource_manifest')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    private function createDeployerConfigurationActivityTables(): void
    {
        Schema::connection('deployer')->create('configuration_reviews', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->longText('document');
            $table->longText('bindings');
            $table->json('summary');
            $table->timestamp('expires_at');
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('deployer')->create('configuration_applications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('configuration_review_id');
            $table->string('status');
            $table->timestamp('locally_applied_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('deployer')->create('configuration_operations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('configuration_application_id');
            $table->string('environment_slug');
            $table->unsignedBigInteger('environment_id')->nullable();
            $table->unsignedBigInteger('build_id')->nullable();
            $table->string('kind');
            $table->string('status');
            $table->longText('payload');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('failure_code')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    private function addIdentityMap(
        string $sourceEntity,
        string $sourceId,
        string $canonicalEntity,
        string $canonicalId,
        string $sourceProduct = 'deployer',
    ): void {
        DB::connection('core')->table('legacy_identity_maps')->insert([
            'id' => (string) Str::ulid(),
            'source_product' => $sourceProduct,
            'source_entity' => $sourceEntity,
            'source_id' => $sourceId,
            'canonical_entity' => $canonicalEntity,
            'canonical_id' => $canonicalId,
            'status' => 'reconciled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedDeployerProjectAndBuilds(): void
    {
        DB::connection('deployer')->table('users')->insert([
            'id' => 17,
            'current_organization_id' => 60,
        ]);
        DB::connection('deployer')->table('organizations')->insert([
            'id' => 50,
            'owner_id' => 17,
        ]);
        DB::connection('deployer')->table('projects')->insert([
            'id' => 31,
            'organization_id' => 50,
        ]);
        DB::connection('deployer')->table('environments')->insert([
            ['id' => 41, 'project_id' => 31, 'server_id' => null, 'website_id' => null, 'name' => 'Production', 'slug' => 'production', 'type' => 'production'],
            ['id' => 42, 'project_id' => 31, 'server_id' => null, 'website_id' => null, 'name' => 'Unmapped', 'slug' => 'unmapped', 'type' => 'staging'],
        ]);
        DB::connection('deployer')->table('builds')->insert([
            ['id' => 100, 'environment_id' => 41, 'status' => 'succeeded', 'revision' => 'abc123456789abcdef', 'release_name' => null, 'created_at' => now()->subMinute(), 'updated_at' => now(), 'started_at' => now()->subMinutes(2), 'finished_at' => now()],
            ['id' => 101, 'environment_id' => 42, 'status' => 'failed', 'revision' => 'unmapped-build-revision', 'release_name' => null, 'created_at' => now(), 'updated_at' => now(), 'started_at' => now(), 'finished_at' => now()],
            ['id' => 102, 'environment_id' => 41, 'status' => 'awaiting_approval', 'revision' => 'def987654321abcdef', 'release_name' => null, 'created_at' => now(), 'updated_at' => now(), 'started_at' => null, 'finished_at' => null],
        ]);

        foreach ([
            ['project', '31', null, 'Checkout app'],
            ['environment', '41', '01J8AA00000000000000000001', 'Production environment'],
        ] as [$resourceType, $resourceId, $environmentId, $name]) {
            DB::connection('core')->table('project_resources')->insert([
                'id' => (string) Str::ulid(),
                'project_id' => $this->projectId,
                'environment_id' => $environmentId,
                'product' => 'deployer',
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'name' => $name,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
