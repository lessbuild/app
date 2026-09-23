<?php

namespace Tests\Feature\Core;

use App\Core\Data\Projects\ProjectProductSnapshotState;
use App\Core\Data\Projects\ProjectSetupStepState;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project as CoreProject;
use App\Core\Models\ProjectEnvironment;
use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Models\User as DeployerUser;
use App\Modules\Deployer\Services\Core\DeployerProjectLink;
use App\Modules\Deployer\Services\Core\DeployerProjectSetup;
use App\Modules\Deployer\Services\Core\DeployerProjectSummary;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DeployerProjectEnvironmentSetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::connection('core')->create('legacy_identity_maps', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('source_product', 24);
            $table->string('source_entity', 100);
            $table->string('source_id', 191);
            $table->string('canonical_entity', 100);
            $table->char('canonical_id', 26);
            $table->string('status', 24);
            $table->timestamps();
        });
        Schema::connection('core')->create('project_environments', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('project_id', 26);
            $table->string('name');
            $table->string('slug');
            $table->string('environment_type');
            $table->string('status');
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
            $table->string('status', 24)->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        foreach (['project_resources', 'project_environments', 'legacy_identity_maps'] as $table) {
            Schema::connection('core')->dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_deployer_setup_reports_repository_and_deployment_status_per_mapped_environment(): void
    {
        $deployerUser = DeployerUser::factory()->create();
        $coreUserId = (string) Str::ulid();
        $coreProjectId = (string) Str::ulid();
        $deployerProject = $deployerUser->currentOrganization->projects()->create([
            'created_by' => $deployerUser->getKey(),
            'name' => 'Signal app',
            'slug' => 'signal-app',
            'description' => 'Environment setup fixture',
        ]);
        $production = $deployerProject->environments()->create([
            'name' => 'Production',
            'slug' => 'production',
            'type' => 'production',
            'branch' => 'main',
        ]);
        $staging = $deployerProject->environments()->create([
            'name' => 'Staging',
            'slug' => 'staging',
            'type' => 'staging',
            'branch' => 'develop',
        ]);
        $provider = $deployerUser->providers()->create([
            'name' => 'GitHub',
            'provider' => 'github',
            'token' => 'test-token',
            'description' => 'Setup fixture',
        ]);
        $server = $deployerUser->servers()->create(['name' => 'Production server']);
        $website = $deployerUser->websites()->create([
            'server_id' => $server->getKey(),
            'name' => 'Signal production',
            'url' => 'signal.example.test',
            'description' => 'Production setup fixture',
            'environment' => 'APP_ENV=production',
        ]);
        $production->update(['website_id' => $website->getKey()]);
        $repository = $deployerUser->repositories()->create([
            'provider_id' => $provider->getKey(),
            'website_id' => $website->getKey(),
            'name' => 'Signal source',
            'url' => 'github.com/example/signal.git',
            'description' => 'Setup fixture',
        ]);
        $repository->builds()->create([
            'environment_id' => $production->getKey(),
            'status' => Build::STATUS_SUCCEEDED,
            'revision' => str_repeat('a', 40),
            'commit_message' => 'Production setup complete',
        ]);

        DB::connection('core')->table('legacy_identity_maps')->insert([
            'id' => (string) Str::ulid(),
            'source_product' => 'deployer',
            'source_entity' => 'user',
            'source_id' => (string) $deployerUser->getKey(),
            'canonical_entity' => 'user',
            'canonical_id' => $coreUserId,
            'status' => 'reconciled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $environmentResourceIds = [];
        $canonicalEnvironmentIds = [];

        foreach ([
            [$production, 'Production', 'production'],
            [$staging, 'Staging', 'staging'],
        ] as [$sourceEnvironment, $name, $type]) {
            $canonicalEnvironmentId = (string) Str::ulid();
            $canonicalEnvironmentIds[$type] = $canonicalEnvironmentId;
            DB::connection('core')->table('project_environments')->insert([
                'id' => $canonicalEnvironmentId,
                'project_id' => $coreProjectId,
                'name' => $name,
                'slug' => $type,
                'environment_type' => $type,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $resourceId = (string) Str::ulid();
            $environmentResourceIds[$type] = $resourceId;
            DB::connection('core')->table('project_resources')->insert([
                'id' => $resourceId,
                'project_id' => $coreProjectId,
                'environment_id' => $canonicalEnvironmentId,
                'product' => 'deployer',
                'resource_type' => 'environment',
                'resource_id' => (string) $sourceEnvironment->getKey(),
                'name' => $name,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::connection('core')->table('project_resources')->insert([
            'id' => (string) Str::ulid(),
            'project_id' => $coreProjectId,
            'product' => 'deployer',
            'resource_type' => 'project',
            'resource_id' => (string) $deployerProject->getKey(),
            'name' => $deployerProject->name,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $platformUser = (new PlatformUser)->forceFill(['id' => $coreUserId]);
        $coreProject = (new CoreProject)->forceFill(['id' => $coreProjectId]);
        $steps = (new DeployerProjectSetup(app(DeployerProjectLink::class)))
            ->steps($platformUser, $coreProject);
        $productionRepository = collect($steps)->firstWhere('id', 'deployer.repository.'.$environmentResourceIds['production']);
        $productionDeployment = collect($steps)->firstWhere('id', 'deployer.deployment.'.$environmentResourceIds['production']);
        $stagingRepository = collect($steps)->first(fn ($step): bool => $step->contextName === 'Staging' && str_starts_with($step->id, 'deployer.repository.'));
        $stagingDeployment = collect($steps)->first(fn ($step): bool => $step->contextName === 'Staging' && str_starts_with($step->id, 'deployer.deployment.'));

        $this->assertNotNull($productionRepository);
        $this->assertNotNull($productionDeployment);
        $this->assertNotNull($stagingRepository);
        $this->assertNotNull($stagingDeployment);
        $this->assertSame(ProjectSetupStepState::Complete, $productionRepository->state);
        $this->assertSame(ProjectSetupStepState::Complete, $productionDeployment->state);
        $this->assertSame(ProjectSetupStepState::NeedsAction, $stagingRepository->state);
        $this->assertSame(ProjectSetupStepState::NeedsAction, $stagingDeployment->state);

        $summaryProvider = new DeployerProjectSummary(app(DeployerProjectLink::class));
        $productionSummary = $summaryProvider->summarizeForEnvironment(
            $platformUser,
            $coreProject,
            ProjectEnvironment::query()->findOrFail($canonicalEnvironmentIds['production']),
        );
        $stagingSummary = $summaryProvider->summarizeForEnvironment(
            $platformUser,
            $coreProject,
            ProjectEnvironment::query()->findOrFail($canonicalEnvironmentIds['staging']),
        );

        $this->assertSame(ProjectProductSnapshotState::Current, $productionSummary?->state);
        $this->assertStringContainsString('Production', $productionSummary?->title ?? '');
        $this->assertSame(ProjectProductSnapshotState::Empty, $stagingSummary?->state);
        $this->assertSame('No deployments have been recorded for this mapped environment yet.', $stagingSummary?->detail);
    }
}
