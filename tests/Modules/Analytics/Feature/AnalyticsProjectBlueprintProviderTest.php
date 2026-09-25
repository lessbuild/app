<?php

namespace Tests\Modules\Analytics\Feature;

use App\Core\Data\Blueprints\BlueprintStepAttempt;
use App\Core\Data\Blueprints\BlueprintTarget;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project as CoreProject;
use App\Core\Models\ProjectBlueprint;
use App\Core\Models\ProjectBlueprintRun;
use App\Core\Models\ProjectBlueprintStep;
use App\Core\Models\ProjectBlueprintVersion;
use App\Core\Models\ProjectEnvironment;
use App\Core\Models\ProjectMembership;
use App\Core\Models\ProjectProduct;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Core\Models\WorkspaceMembership;
use App\Core\Models\WorkspaceProductAccess;
use App\Core\Services\Blueprints\BlueprintAuthority;
use App\Core\Services\Blueprints\BlueprintFingerprint;
use App\Modules\Analytics\Enums\WorkspaceRole;
use App\Modules\Analytics\Models\BlueprintApplicationReceipt;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Models\User as AnalyticsUser;
use App\Modules\Analytics\Models\Workspace as AnalyticsWorkspace;
use App\Modules\Analytics\Services\Core\AnalyticsProjectBlueprintProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Modules\Analytics\RefreshAnalyticsDatabase;
use Tests\TestCase;

final class AnalyticsProjectBlueprintProviderTest extends TestCase
{
    use RefreshAnalyticsDatabase;

    public function test_normalization_is_secret_free_ordered_and_rejects_duplicate_environment_keys(): void
    {
        $provider = app(AnalyticsProjectBlueprintProvider::class);
        $normalized = $provider->normalize(['sites' => [[
            'environment' => 'production', 'name' => ' Marketing ', 'domains' => ['HTTPS://WWW.Example.test/path', 'www.example.test'], 'timezone' => 'UTC',
        ]]]);

        $this->assertSame(['sites' => [[
            'environment' => 'production', 'name' => 'Marketing', 'domains' => ['www.example.test'], 'timezone' => 'UTC',
        ]]], $normalized);

        try {
            $provider->normalize(['sites' => [
                ['environment' => 'production', 'name' => 'One', 'domains' => ['one.example.test'], 'timezone' => 'UTC'],
                ['environment' => 'production', 'name' => 'Two', 'domains' => ['two.example.test'], 'timezone' => 'UTC'],
            ]]);
            $this->fail('A blueprint must select at most one site per environment key.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('configuration.sites.1.environment', $exception->errors());
        }
    }

    public function test_preview_discloses_site_creation_and_setup_without_writing_native_data(): void
    {
        [$actor, $workspace, $project, $environment] = $this->mappedOwnerFixture();
        $target = new BlueprintTarget((string) $actor->getKey(), (string) $workspace->getKey(), (string) $project->getKey(), [
            'production' => ['id' => (string) $environment->getKey(), 'name' => 'Production', 'type' => 'production'],
        ]);
        $configuration = ['sites' => [[
            'environment' => 'production', 'name' => 'Marketing site', 'domains' => ['www.example.test'], 'timezone' => 'UTC',
        ]]];

        $preview = app(AnalyticsProjectBlueprintProvider::class)->preview($target, $configuration);

        $this->assertSame([], $preview->blockers);
        $this->assertContains('Create 1 Analytics site(s).', $preview->changes);
        $this->assertSame(1, $preview->planImpact['requested_new_sites']);
        $this->assertNotEmpty($preview->requirements);
        $this->assertDatabaseCount('sites', 0, 'analytics');
        $this->assertDatabaseCount('blueprint_application_receipts', 0, 'analytics');

        $wrongScope = app(AnalyticsProjectBlueprintProvider::class)->preview($target, ['sites' => [[
            'environment' => 'staging', 'name' => 'Wrong scope', 'domains' => ['staging.example.test'], 'timezone' => 'UTC',
        ]]]);
        $this->assertNotEmpty($wrongScope->blockers);
        $this->assertDatabaseCount('sites', 0, 'analytics');
    }

    public function test_native_apply_receipt_makes_source_provisioning_replay_safe(): void
    {
        [$actor, $workspace, $project, $environment] = $this->mappedOwnerFixture();
        $target = new BlueprintTarget((string) $actor->getKey(), (string) $workspace->getKey(), (string) $project->getKey(), [
            'production' => ['id' => (string) $environment->getKey(), 'name' => 'Production', 'type' => 'production'],
        ]);
        $configuration = ['sites' => [[
            'environment' => 'production', 'name' => 'Marketing site', 'domains' => ['www.example.test'], 'timezone' => 'UTC',
        ]]];
        $provider = app(AnalyticsProjectBlueprintProvider::class);
        $preview = $provider->preview($target, $configuration);
        $this->assertTrue($preview->ready());
        $attempt = $this->acceptedAttempt($actor, $workspace, $project, $target, $configuration, $preview->authority);

        $first = $provider->apply($attempt);
        $replay = $provider->apply($attempt);

        $this->assertSame($first->toArray(), $replay->toArray());
        $this->assertDatabaseCount('sites', 1, 'analytics');
        $this->assertDatabaseCount('blueprint_application_receipts', 1, 'analytics');
        $this->assertSame('www.example.test', Site::query()->sole()->domains[0]);
        $this->assertSame('production', $first->resources[0]->environmentKey);
        $this->assertDatabaseMissing('project_resources', ['product' => 'analytics'], 'core');
        $receipt = BlueprintApplicationReceipt::query()->sole();
        $this->assertSame(DB::connection('core')->table('legacy_identity_maps')
            ->where('source_product', 'analytics')->where('source_entity', 'user')->value('source_id'), $receipt->actor_source_id);
        $this->assertNull($receipt->result['resources'][0]['parentSourceId']);
    }

    public function test_preview_blocks_timezone_reinterpretation_for_mapped_sites_with_events(): void
    {
        [$actor, $workspace, $project, $environment] = $this->mappedOwnerFixture();
        $site = $workspace->sites()->create(['name' => 'Existing', 'domains' => ['example.test'], 'timezone' => 'UTC']);
        $site->events()->create([
            'event_id' => (string) Str::ulid(), 'name' => 'page_view', 'path' => '/', 'occurred_at' => now(),
            'received_at' => now(), 'properties' => [],
        ]);
        DB::connection('core')->table('project_resources')->insert([
            'id' => (string) Str::ulid(), 'project_id' => $project->getKey(), 'environment_id' => $environment->getKey(),
            'product' => 'analytics', 'resource_type' => 'site', 'resource_id' => (string) $site->getKey(),
            'name' => 'Existing', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $target = new BlueprintTarget((string) $actor->getKey(), (string) $workspace->getKey(), (string) $project->getKey(), [
            'production' => ['id' => (string) $environment->getKey(), 'name' => 'Production', 'type' => 'production'],
        ]);

        $preview = app(AnalyticsProjectBlueprintProvider::class)->preview($target, ['sites' => [[
            'environment' => 'production', 'name' => 'Existing', 'domains' => ['example.test'], 'timezone' => 'America/Los_Angeles',
        ]]]);

        $this->assertNotEmpty($preview->blockers);
        $this->assertSame('UTC', $site->fresh()->timezone);
    }

    public function test_apply_updates_only_the_exact_mapped_site_and_clears_domain_verification(): void
    {
        [$actor, $workspace, $project, $environment] = $this->mappedOwnerFixture();
        $site = $workspace->sites()->create([
            'name' => 'Existing marketing site', 'domains' => ['old.example.test'], 'timezone' => 'UTC', 'verified_at' => now(),
        ]);
        DB::connection('core')->table('project_resources')->insert([
            'id' => (string) Str::ulid(), 'project_id' => $project->getKey(), 'environment_id' => $environment->getKey(),
            'product' => 'analytics', 'resource_type' => 'site', 'resource_id' => (string) $site->getKey(),
            'name' => $site->name, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $target = new BlueprintTarget((string) $actor->getKey(), (string) $workspace->getKey(), (string) $project->getKey(), [
            'production' => ['id' => (string) $environment->getKey(), 'name' => 'Production', 'type' => 'production'],
        ]);
        $configuration = ['sites' => [[
            'environment' => 'production', 'name' => 'Updated marketing site', 'domains' => ['new.example.test'], 'timezone' => 'UTC',
        ]]];
        $provider = app(AnalyticsProjectBlueprintProvider::class);
        $preview = $provider->preview($target, $configuration);
        $this->assertContains('Update 1 mapped Analytics site(s).', $preview->changes);
        $attempt = $this->acceptedAttempt($actor, $workspace, $project, $target, $configuration, $preview->authority);

        $result = $provider->apply($attempt);

        $this->assertSame((string) $site->getKey(), $result->resources[0]->sourceId);
        $this->assertSame('Updated marketing site', $site->fresh()->name);
        $this->assertSame(['new.example.test'], $site->fresh()->domains);
        $this->assertNull($site->fresh()->verified_at);
        $this->assertDatabaseCount('sites', 1, 'analytics');
    }

    /** @return array{PlatformUser, CoreWorkspace, CoreProject, ProjectEnvironment} */
    private function mappedOwnerFixture(): array
    {
        config([
            'platform.products.analytics.auth_authority' => 'core', 'analytics.plan_authority' => 'legacy',
            'platform.products.analytics.enabled' => true,
        ]);
        $actor = PlatformUser::query()->forceCreate([
            'id' => (string) Str::ulid(), 'name' => 'Owner', 'email' => 'analytics-blueprint@example.test',
            'email_normalized' => 'analytics-blueprint@example.test', 'password' => 'hashed', 'status' => 'active',
        ]);
        $coreWorkspace = CoreWorkspace::query()->create([
            'owner_user_id' => $actor->getKey(), 'name' => 'Shared workspace', 'slug' => 'shared-'.Str::lower(Str::random(8)), 'status' => 'active',
        ]);
        $membership = WorkspaceMembership::query()->create([
            'workspace_id' => $coreWorkspace->getKey(), 'user_id' => $actor->getKey(), 'role' => 'owner', 'status' => 'active', 'joined_at' => now(),
        ]);
        WorkspaceProductAccess::query()->create(['membership_id' => $membership->getKey(), 'product' => 'analytics', 'role' => 'owner', 'status' => 'active']);
        $project = CoreProject::query()->create([
            'workspace_id' => $coreWorkspace->getKey(), 'created_by_user_id' => $actor->getKey(), 'name' => 'Project',
            'slug' => 'project-'.Str::lower(Str::random(8)), 'status' => 'active',
        ]);
        ProjectMembership::query()->create(['project_id' => $project->getKey(), 'user_id' => $actor->getKey(), 'role' => 'owner', 'status' => 'active']);
        ProjectProduct::query()->create(['project_id' => $project->getKey(), 'product' => 'analytics', 'status' => 'active']);
        $environment = ProjectEnvironment::query()->create([
            'project_id' => $project->getKey(), 'created_by_user_id' => $actor->getKey(), 'name' => 'Production', 'slug' => 'production',
            'environment_type' => 'production', 'status' => 'active',
        ]);

        $nativeUser = AnalyticsUser::query()->forceCreate([
            'name' => $actor->name, 'email' => 'native-analytics@example.test', 'password' => 'hashed', 'platform_user_id' => $actor->getKey(),
        ]);
        $nativeWorkspace = AnalyticsWorkspace::query()->create(['name' => 'Native workspace']);
        $nativeWorkspace->users()->attach($nativeUser, ['role' => WorkspaceRole::Owner->value]);
        foreach ([
            ['user', (string) $nativeUser->getKey(), 'user', (string) $actor->getKey()],
            ['workspace', (string) $nativeWorkspace->getKey(), 'workspace', (string) $coreWorkspace->getKey()],
        ] as [$sourceEntity, $sourceId, $canonicalEntity, $canonicalId]) {
            DB::connection('core')->table('legacy_identity_maps')->insert([
                'id' => (string) Str::ulid(), 'source_product' => 'analytics', 'source_entity' => $sourceEntity,
                'source_id' => $sourceId, 'canonical_entity' => $canonicalEntity, 'canonical_id' => $canonicalId,
                'status' => 'reconciled', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return [$actor, $coreWorkspace, $project, $environment];
    }

    private function acceptedAttempt(PlatformUser $actor, CoreWorkspace $workspace, CoreProject $project, BlueprintTarget $target, array $configuration, array $nativeAuthority): BlueprintStepAttempt
    {
        $blueprint = ProjectBlueprint::query()->create([
            'workspace_id' => $workspace->getKey(), 'created_by_user_id' => $actor->getKey(), 'name' => 'Fixture', 'latest_version' => 1,
        ]);
        $version = ProjectBlueprintVersion::query()->create([
            'project_blueprint_id' => $blueprint->getKey(), 'created_by_user_id' => $actor->getKey(), 'version' => 1,
            'definition' => ['environments' => [['key' => 'production', 'name' => 'Production', 'type' => 'production']], 'products' => ['analytics' => $configuration]],
            'definition_hash' => str_repeat('a', 64),
        ]);
        $runId = (string) Str::ulid();
        ProjectBlueprintRun::query()->create([
            'id' => $runId, 'workspace_id' => $workspace->getKey(), 'project_id' => $project->getKey(),
            'project_blueprint_version_id' => $version->getKey(), 'requested_by_user_id' => $actor->getKey(),
            'idempotency_key' => (string) Str::uuid(), 'intent_hash' => str_repeat('b', 64),
            'environment_bindings' => $target->environments, 'status' => 'processing',
        ]);
        $stepId = (string) Str::ulid();
        $payloadHash = BlueprintFingerprint::make([
            'step' => $stepId, 'target' => $target->toArray(), 'configuration' => $configuration, 'authority' => $nativeAuthority,
        ]);
        ProjectBlueprintStep::query()->create([
            'id' => $stepId, 'project_blueprint_run_id' => $runId, 'product' => 'analytics', 'target' => $target->toArray(),
            'configuration' => $configuration, 'native_authority' => $nativeAuthority,
            'core_binding_hash' => app(BlueprintAuthority::class)->bindingHash($target, 'analytics'), 'payload_hash' => $payloadHash,
            'status' => 'processing', 'generation' => 1, 'lease_token' => Str::random(64), 'lease_expires_at' => now()->addMinutes(10),
        ]);

        return ProjectBlueprintStep::query()->findOrFail($stepId)->attempt();
    }
}
