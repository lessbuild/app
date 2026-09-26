<?php

namespace Tests\Feature\Deployer;

use App\Core\Data\Blueprints\BlueprintStepAttempt;
use App\Core\Data\Blueprints\BlueprintTarget;
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
use App\Modules\Deployer\Actions\Project\InstallBlueprintRecipeSnapshotAction;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\EnvironmentBlueprintRecipe;
use App\Modules\Deployer\Models\Project as NativeProject;
use App\Modules\Deployer\Models\Recipe;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\ApplicationTemplateCatalog;
use App\Modules\Deployer\Services\Core\DeployerProjectBlueprintProvider;
use App\Modules\Deployer\Services\Entitlements;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Focused regression coverage for ambiguous canonical environment mapping claims. */
final class InstallBlueprintRecipeSnapshotActionIntegrityTest extends TestCase
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

    public function test_install_action_and_project_page_reject_foreign_claims_on_the_same_canonical_environment(): void
    {
        $prepared = $this->preparedPersonalSnapshot('ambiguous-canonical-environment');
        $fixture = $prepared['fixture'];
        $foreignProject = CoreProject::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(), 'created_by_user_id' => $fixture['actor']->getKey(),
            'name' => 'Foreign project', 'slug' => 'ambiguous-foreign-'.Str::lower(Str::random(8)), 'status' => 'active',
        ]);
        $foreignNativeProject = $fixture['organization']->projects()->create([
            'name' => 'Foreign native project', 'slug' => 'ambiguous-native-'.Str::lower(Str::random(8)),
            'created_by' => $fixture['nativeActor']->getKey(),
        ]);
        $foreignNativeEnvironment = $foreignNativeProject->environments()->create([
            'name' => 'Production', 'slug' => 'production', 'type' => 'production',
        ]);
        ProjectResource::query()->create([
            'project_id' => $foreignProject->getKey(),
            'environment_id' => $fixture['target']->environments['production']['id'],
            'product' => 'deployer', 'resource_type' => 'environment',
            'resource_id' => (string) $foreignNativeEnvironment->getKey(),
            'name' => $foreignNativeEnvironment->name, 'status' => 'active',
        ]);

        try {
            app(InstallBlueprintRecipeSnapshotAction::class)->handle(
                $fixture['nativeActor'], $prepared['nativeProject'], $prepared['nativeEnvironment'], $prepared['snapshot'], true,
            );
            $this->fail('A foreign project claim on the canonical environment must block snapshot installation.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertNull($prepared['snapshot']->fresh()->installed_recipe_id);

        $response = $this->actingAs($fixture['actor'], 'platform')
            ->get(route('projects.show', $prepared['nativeProject']));
        $response->assertOk()->assertDontSee('Reviewed workspace recipe');
    }

    /** @return array<string, mixed> */
    private function preparedPersonalSnapshot(string $slug): array
    {
        $fixture = $this->scenario($slug);
        $recipe = new Recipe;
        $recipe->forceFill([
            'user_id' => $fixture['nativeActor']->getKey(), 'organization_id' => null,
            'name' => 'Reviewed workspace recipe', 'description' => null, 'script' => 'echo prepared',
            'is_published' => false, 'published_at' => null, 'gallery_revision_at' => null,
            'source_recipe_id' => null, 'source_revision_at' => null,
        ])->save();
        $recipe->forceFill(['organization_id' => null])->save();
        $configuration = [
            'schema_version' => 1,
            'project_name' => 'Checkout',
            'template' => array_key_first(config('application-templates')),
            'environment_recipes' => [['environment' => 'production', 'recipe_ids' => [(int) $recipe->getKey()]]],
        ];
        $provider = new DeployerProjectBlueprintProvider(
            app(BlueprintAuthority::class), app(LegacyIdentityResolver::class), app(ApplicationTemplateCatalog::class),
            app(Entitlements::class), app(ApplyApplicationTemplate::class),
        );
        $preview = $provider->preview($fixture['target'], $configuration);
        $this->assertTrue($preview->ready(), implode('; ', $preview->blockers));
        $attempt = $this->acceptedAttempt($fixture, $configuration, $preview->authority);
        $result = $provider->apply($attempt);
        $nativeProject = NativeProject::query()->whereKey($result->resources[0]->sourceId)->sole();
        $environmentResource = collect($result->resources)->firstWhere('environmentKey', 'production');
        $nativeEnvironment = Environment::query()->whereKey($environmentResource->sourceId)->sole();
        ProjectResource::query()->create([
            'project_id' => $fixture['project']->getKey(), 'environment_id' => null,
            'product' => 'deployer', 'resource_type' => 'project', 'resource_id' => (string) $nativeProject->getKey(),
            'name' => $nativeProject->name, 'status' => 'active',
        ]);
        ProjectResource::query()->create([
            'project_id' => $fixture['project']->getKey(),
            'environment_id' => $fixture['target']->environments['production']['id'],
            'product' => 'deployer', 'resource_type' => 'environment', 'resource_id' => (string) $nativeEnvironment->getKey(),
            'name' => $nativeEnvironment->name, 'status' => 'active',
        ]);

        return [
            'fixture' => $fixture, 'nativeProject' => $nativeProject, 'nativeEnvironment' => $nativeEnvironment,
            'snapshot' => EnvironmentBlueprintRecipe::query()->where('step_id', $attempt->stepId)->sole(),
        ];
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

    /** @param array<string, mixed> $fixture @param array<string, mixed> $configuration */
    private function acceptedAttempt(array $fixture, array $configuration, array $nativeAuthority): BlueprintStepAttempt
    {
        $blueprint = ProjectBlueprint::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(), 'created_by_user_id' => $fixture['actor']->getKey(),
            'name' => 'Fixture', 'latest_version' => 1,
        ]);
        $version = ProjectBlueprintVersion::query()->create([
            'project_blueprint_id' => $blueprint->getKey(), 'created_by_user_id' => $fixture['actor']->getKey(),
            'version' => 1,
            'definition' => [
                'environments' => [['key' => 'production', 'name' => 'Production', 'type' => 'production']],
                'products' => ['deployer' => $configuration],
            ],
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
            'step' => $stepId, 'target' => $fixture['target']->toArray(), 'configuration' => $configuration,
            'authority' => $nativeAuthority,
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
