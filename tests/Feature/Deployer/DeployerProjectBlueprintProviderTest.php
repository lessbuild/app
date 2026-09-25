<?php

namespace Tests\Feature\Deployer;

use App\Core\Data\Blueprints\BlueprintStepAttempt;
use App\Core\Data\Blueprints\BlueprintTarget;
use App\Core\Exceptions\Blueprints\BlueprintBlocked;
use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project as CoreProject;
use App\Core\Models\ProjectBlueprint;
use App\Core\Models\ProjectBlueprintRun;
use App\Core\Models\ProjectBlueprintStep;
use App\Core\Models\ProjectBlueprintVersion;
use App\Core\Models\ProjectEnvironment;
use App\Core\Models\ProjectMembership;
use App\Core\Models\ProjectProduct;
use App\Core\Models\ProjectResource;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Core\Models\WorkspaceMembership;
use App\Core\Models\WorkspaceProductAccess;
use App\Core\Services\Blueprints\BlueprintAuthority;
use App\Core\Services\Blueprints\BlueprintFingerprint;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Deployer\Actions\Project\ApplyApplicationTemplate;
use App\Modules\Deployer\Models\Project as NativeProject;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\ApplicationTemplateCatalog;
use App\Modules\Deployer\Services\Core\DeployerProjectBlueprintProvider;
use App\Modules\Deployer\Services\Entitlements;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Regression cases for Deployer blueprint provisioning; intentionally not executed here. */
final class DeployerProjectBlueprintProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['core', 'deployer'] as $connection) {
            config(["database.connections.{$connection}.database" => ':memory:']);
            DB::purge($connection);
            $this->assertSame(0, Artisan::call('platform:migrate', ['module' => $connection]));
        }
        config([
            'platform.products.deployer.enabled' => true,
            'platform.products.deployer.auth_authority' => 'core',
            'billing.enforce_entitlements' => false,
        ]);
    }

    public function test_normalization_keeps_only_versioned_secret_free_configuration(): void
    {
        $provider = $this->provider();
        $template = array_key_first(config('application-templates'));
        $configuration = $provider->normalize([
            'schema_version' => 1, 'project_name' => '  Checkout  ', 'template' => $template,
            'environment_recipes' => [['environment' => 'production', 'recipe_ids' => [17, 18]]],
        ]);

        $this->assertSame('Checkout', $configuration['project_name']);
        $this->assertSame(1, $configuration['schema_version']);
        $this->assertSame([17, 18], $configuration['environment_recipes'][0]['recipe_ids']);
        $this->assertArrayNotHasKey('script', $configuration);
        $this->assertSame(['environment', 'recipe_ids'], array_keys($configuration['environment_recipes'][0]));
    }

    public function test_normalization_rejects_unknown_fields_and_recipe_script_payloads(): void
    {
        $provider = $this->provider();
        $template = array_key_first(config('application-templates'));

        $this->expectException(ValidationException::class);
        $provider->normalize([
            'schema_version' => 1, 'project_name' => 'Checkout', 'template' => $template,
            'script' => 'must never be accepted or persisted',
            'environment_recipes' => [['environment' => 'production', 'recipe_ids' => [], 'script' => 'unsafe']],
        ]);
    }

    public function test_normalization_rejects_unsupported_versions_and_non_list_recipe_ids(): void
    {
        $provider = $this->provider();
        $template = array_key_first(config('application-templates'));
        try {
            $provider->normalize(['schema_version' => 2, 'project_name' => 'Checkout', 'template' => $template, 'environment_recipes' => []]);
            $this->fail('Unsupported configuration versions must be rejected.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $this->expectException(ValidationException::class);
        $provider->normalize([
            'schema_version' => 1, 'project_name' => 'Checkout', 'template' => $template,
            'environment_recipes' => [['environment' => 'production', 'recipe_ids' => ['named' => 17]]],
        ]);
    }

    public function test_initial_template_application_replay_and_protected_permission_loss_use_real_authority(): void
    {
        $fixture = $this->scenario('blueprint-initial');
        $provider = $this->provider();
        $configuration = $this->configuration();
        $preview = $provider->preview($fixture['target'], $configuration);
        $this->assertTrue($preview->ready(), implode('; ', $preview->blockers));
        $attempt = $this->acceptedAttempt($fixture, $configuration, $preview->authority);

        $originalTemplates = config('application-templates');
        $changedTemplates = $originalTemplates;
        $changedTemplates[$configuration['template']]['build_command'] = 'changed-after-preview';
        config(['application-templates' => $changedTemplates]);
        try {
            $provider->apply($attempt);
            $this->fail('Template default drift must block the accepted configuration.');
        } catch (BlueprintBlocked $exception) {
            $this->assertSame('native_state_changed', $exception->reason);
        }
        config(['application-templates' => $originalTemplates]);

        $first = $provider->apply($attempt);
        $replay = $provider->apply($attempt);
        $this->assertSame($first->toArray(), $replay->toArray());
        $this->assertSame('project', $first->resources[0]->type);
        $this->assertSame('environment', $first->resources[1]->type);
        $this->assertSame($first->resources[0]->sourceId, $first->resources[1]->parentSourceId);
        $nativeProject = NativeProject::query()->where('organization_id', $fixture['organization']->getKey())->sole();
        $this->assertSame($configuration['template'], $nativeProject->preset);
        $this->assertNotNull($nativeProject->template_version);
        $this->assertSame(1, $nativeProject->environments()->count());
        $this->assertDatabaseCount('blueprint_application_receipts', 1, 'deployer');

        $replacementOwner = User::factory()->create();
        $fixture['organization']->forceFill(['owner_id' => $replacementOwner->getKey()])->save();
        $fixture['organization']->members()->updateExistingPivot($fixture['nativeActor']->getKey(), ['role' => 'developer']);
        try {
            $provider->apply($attempt);
            $this->fail('A replay must recheck current native protected-environment permission.');
        } catch (BlueprintBlocked $exception) {
            $this->assertSame('native_access_changed', $exception->reason);
        }
    }

    public function test_core_authority_revocation_after_native_project_creation_rolls_back_source_writes(): void
    {
        $fixture = $this->scenario('blueprint-revoked');
        $provider = $this->provider();
        $configuration = $this->configuration();
        $preview = $provider->preview($fixture['target'], $configuration);
        $attempt = $this->acceptedAttempt($fixture, $configuration, $preview->authority);
        $event = 'eloquent.created: '.NativeProject::class;
        Event::listen($event, function () use ($fixture): void {
            WorkspaceProductAccess::query()->where('membership_id', $fixture['membership']->getKey())
                ->where('product', 'deployer')->update(['status' => 'revoked', 'revoked_at' => now()]);
        });

        try {
            try {
                $provider->apply($attempt);
                $this->fail('Core authority revoked during local writes must prevent commit.');
            } catch (BlueprintBlocked $exception) {
                $this->assertSame('authority_changed', $exception->reason);
            }
        } finally {
            Event::forget($event);
        }

        $this->assertSame(0, NativeProject::query()->where('organization_id', $fixture['organization']->getKey())->count());
        $this->assertDatabaseCount('blueprint_application_receipts', 0, 'deployer');
    }

    public function test_foreign_core_environment_mapping_blocks_preview(): void
    {
        $fixture = $this->scenario('blueprint-foreign-map');
        $foreignProject = CoreProject::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(), 'created_by_user_id' => $fixture['actor']->getKey(),
            'name' => 'Foreign project', 'slug' => 'blueprint-foreign-'.Str::lower(Str::random(8)), 'status' => 'active',
        ]);
        ProjectMembership::query()->create([
            'project_id' => $foreignProject->getKey(), 'user_id' => $fixture['actor']->getKey(), 'role' => 'owner', 'status' => 'active',
        ]);
        $nativeProject = $fixture['organization']->projects()->create([
            'name' => 'Foreign native project', 'slug' => 'foreign-native-'.Str::lower(Str::random(8)), 'created_by' => $fixture['nativeActor']->getKey(),
        ]);
        $nativeEnvironment = $nativeProject->environments()->create(['name' => 'Production', 'slug' => 'production', 'type' => 'production']);
        ProjectResource::query()->create([
            'project_id' => $foreignProject->getKey(), 'environment_id' => $fixture['environment']->getKey(),
            'product' => 'deployer', 'resource_type' => 'environment', 'resource_id' => (string) $nativeEnvironment->getKey(),
            'name' => $nativeEnvironment->name, 'status' => 'active',
        ]);

        $preview = $this->provider()->preview($fixture['target'], $this->configuration());

        $this->assertFalse($preview->ready());
        $this->assertContains('A selected Deployer environment is mapped under a different Core project.', $preview->blockers);
        $this->assertTrue($nativeEnvironment->fresh()->exists);
    }

    /** @return array<string, mixed> */
    private function scenario(string $slug): array
    {
        $nativeActor = User::factory()->create();
        $organization = $nativeActor->currentOrganization;
        $actor = PlatformUser::query()->forceCreate([
            'id' => (string) Str::ulid(), 'name' => 'Platform owner', 'email' => $slug.'@example.test',
            'email_normalized' => $slug.'@example.test', 'status' => 'active', 'email_verified_at' => now(),
        ]);
        $workspace = CoreWorkspace::query()->create([
            'owner_user_id' => $actor->getKey(), 'name' => 'Workspace', 'slug' => $slug.'-workspace', 'status' => 'active',
        ]);
        $membership = WorkspaceMembership::query()->create([
            'workspace_id' => $workspace->getKey(), 'user_id' => $actor->getKey(), 'role' => 'owner', 'status' => 'active',
        ]);
        WorkspaceProductAccess::query()->create([
            'membership_id' => $membership->getKey(), 'product' => 'deployer', 'role' => 'owner', 'status' => 'active',
        ]);
        $project = CoreProject::query()->create([
            'workspace_id' => $workspace->getKey(), 'created_by_user_id' => $actor->getKey(),
            'name' => 'Checkout', 'slug' => $slug.'-project', 'status' => 'active',
        ]);
        ProjectMembership::query()->create([
            'project_id' => $project->getKey(), 'user_id' => $actor->getKey(), 'role' => 'owner', 'status' => 'active',
        ]);
        ProjectProduct::query()->create(['project_id' => $project->getKey(), 'product' => 'deployer', 'status' => 'active']);
        $environment = ProjectEnvironment::query()->create([
            'project_id' => $project->getKey(), 'created_by_user_id' => $actor->getKey(), 'name' => 'Production',
            'slug' => 'production', 'environment_type' => 'production', 'status' => 'active',
        ]);
        LegacyIdentityMap::query()->create([
            'source_product' => 'deployer', 'source_entity' => 'user', 'source_id' => (string) $nativeActor->getKey(),
            'canonical_entity' => 'user', 'canonical_id' => (string) $actor->getKey(), 'status' => 'reconciled',
        ]);
        LegacyIdentityMap::query()->create([
            'source_product' => 'deployer', 'source_entity' => 'organization', 'source_id' => (string) $organization->getKey(),
            'canonical_entity' => 'workspace', 'canonical_id' => (string) $workspace->getKey(), 'status' => 'reconciled',
        ]);

        return [
            'actor' => $actor, 'workspace' => $workspace, 'project' => $project, 'membership' => $membership,
            'nativeActor' => $nativeActor, 'organization' => $organization, 'environment' => $environment,
            'target' => new BlueprintTarget((string) $actor->getKey(), (string) $workspace->getKey(), (string) $project->getKey(), [
                'production' => ['id' => (string) $environment->getKey(), 'name' => 'Production', 'type' => 'production'],
            ]),
        ];
    }

    private function provider(): DeployerProjectBlueprintProvider
    {
        return new DeployerProjectBlueprintProvider(
            app(BlueprintAuthority::class), app(LegacyIdentityResolver::class), app(ApplicationTemplateCatalog::class),
            app(Entitlements::class), app(ApplyApplicationTemplate::class),
        );
    }

    /** @return array<string, mixed> */
    private function configuration(): array
    {
        return ['schema_version' => 1, 'project_name' => 'Checkout', 'template' => array_key_first(config('application-templates')), 'environment_recipes' => []];
    }

    /** @param array<string, mixed> $fixture @param array<string, mixed> $configuration */
    private function acceptedAttempt(array $fixture, array $configuration, array $nativeAuthority): BlueprintStepAttempt
    {
        $blueprint = ProjectBlueprint::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(), 'created_by_user_id' => $fixture['actor']->getKey(), 'name' => 'Fixture', 'latest_version' => 1,
        ]);
        $version = ProjectBlueprintVersion::query()->create([
            'project_blueprint_id' => $blueprint->getKey(), 'created_by_user_id' => $fixture['actor']->getKey(), 'version' => 1,
            'definition' => ['environments' => [['key' => 'production', 'name' => 'Production', 'type' => 'production']], 'products' => ['deployer' => $configuration]],
            'definition_hash' => str_repeat('a', 64),
        ]);
        $runId = (string) Str::ulid();
        ProjectBlueprintRun::query()->create([
            'id' => $runId, 'workspace_id' => $fixture['workspace']->getKey(), 'project_id' => $fixture['project']->getKey(),
            'project_blueprint_version_id' => $version->getKey(), 'requested_by_user_id' => $fixture['actor']->getKey(),
            'idempotency_key' => (string) Str::uuid(), 'intent_hash' => str_repeat('b', 64),
            'environment_bindings' => $fixture['target']->environments, 'status' => 'processing',
        ]);
        $stepId = (string) Str::ulid();
        $payloadHash = BlueprintFingerprint::make([
            'step' => $stepId, 'target' => $fixture['target']->toArray(), 'configuration' => $configuration, 'authority' => $nativeAuthority,
        ]);
        ProjectBlueprintStep::query()->create([
            'id' => $stepId, 'project_blueprint_run_id' => $runId, 'product' => 'deployer',
            'target' => $fixture['target']->toArray(), 'configuration' => $configuration, 'native_authority' => $nativeAuthority,
            'core_binding_hash' => app(BlueprintAuthority::class)->bindingHash($fixture['target'], 'deployer'),
            'payload_hash' => $payloadHash, 'status' => 'processing', 'generation' => 1,
            'lease_token' => Str::random(64), 'lease_expires_at' => now()->addMinutes(10),
        ]);

        return ProjectBlueprintStep::query()->findOrFail($stepId)->attempt();
    }
}
