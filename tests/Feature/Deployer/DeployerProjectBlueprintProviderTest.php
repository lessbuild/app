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
use App\Core\Services\Blueprints\RecordBlueprintResources;
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
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
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
        $recipe = $this->createRecipe($fixture, 'Revocation recipe');
        $configuration = $this->configurationWithRecipe($recipe);
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
        $this->assertDatabaseCount('environment_blueprint_recipes', 0, 'deployer');
    }

    public function test_zero_recipe_completed_receipt_replays_when_snapshot_table_is_absent(): void
    {
        $fixture = $this->scenario('blueprint-zero-recipe-replay-without-store');
        $provider = $this->provider();
        $configuration = $this->configuration();
        $preview = $provider->preview($fixture['target'], $configuration);
        $this->assertTrue($preview->ready(), implode('; ', $preview->blockers));
        $attempt = $this->acceptedAttempt($fixture, $configuration, $preview->authority);

        $first = $provider->apply($attempt);
        $this->assertDatabaseCount('blueprint_application_receipts', 1, 'deployer');
        Schema::connection('deployer')->dropIfExists('environment_blueprint_recipes');

        $replay = $provider->apply($attempt);

        $this->assertSame($first->toArray(), $replay->toArray());
    }

    public function test_recipe_snapshot_is_exact_environment_bound_replayed_without_source_drift_and_never_executed(): void
    {
        $fixture = $this->scenario('blueprint-recipe-snapshot');
        $recipe = $this->createRecipe($fixture, 'Reviewed workspace recipe', [
            'description' => 'Safe metadata description', 'script' => "#!/bin/bash\nprintf 'prepared only\\n'",
        ]);
        $provider = $this->provider();
        $configuration = $this->configurationWithRecipe($recipe);
        $preview = $provider->preview($fixture['target'], $configuration);
        $this->assertTrue($preview->ready(), implode('; ', $preview->blockers));
        $attempt = $this->acceptedAttempt($fixture, $configuration, $preview->authority);

        $first = $provider->apply($attempt);
        $nativeProject = NativeProject::query()->where('organization_id', $fixture['organization']->getKey())->sole();
        $nativeEnvironment = Environment::query()->where('project_id', $nativeProject->getKey())->sole();
        $snapshot = EnvironmentBlueprintRecipe::query()->sole();
        $this->assertSame((string) $nativeEnvironment->getKey(), (string) $snapshot->environment_id);
        $this->assertSame((string) $nativeEnvironment->getKey(), (string) $first->resources[1]->sourceId);
        $this->assertSame('production', $snapshot->environment_key);
        $this->assertSame((string) $fixture['target']->environments['production']['id'], (string) $snapshot->canonical_environment_id);
        $this->assertNull($snapshot->source_user_id, 'Workspace recipe authorship is not needed for post-apply visibility.');
        $this->assertSame($recipe->script, $snapshot->script_snapshot);
        $this->assertNotSame(hash('sha256', $recipe->script), $snapshot->script_fingerprint);
        $this->assertSame(['project', 'environment'], array_map(fn ($resource) => $resource->type, $first->resources));
        $this->assertStringContainsString('Attach a server', implode(' ', $first->requirements));
        $this->assertStringContainsString('does not attach recipes or run scripts', implode(' ', $first->requirements));
        $this->assertStringNotContainsString($recipe->name, implode(' ', $first->requirements));
        $this->assertStringNotContainsString($recipe->script, json_encode($first->toArray(), JSON_THROW_ON_ERROR));
        $this->assertDatabaseCount('recipe_server', 0, 'deployer');
        $this->assertNull($nativeEnvironment->fresh()->server_id);
        $this->assertSame(0, ProjectResource::query()->where('project_id', $fixture['project']->getKey())
            ->where('product', 'deployer')->count(), 'The Core resource acknowledgement has not run yet.');

        $recipe->forceFill([
            'name' => 'Changed after apply', 'script' => 'changed after apply', 'is_published' => false,
            'published_at' => null, 'gallery_revision_at' => null,
        ])->save();
        $recipe->delete();

        $replay = $provider->apply($attempt);
        $this->assertSame($first->toArray(), $replay->toArray());
        $this->assertSame(1, EnvironmentBlueprintRecipe::query()->count());
        $this->assertSame('Reviewed workspace recipe', $snapshot->fresh()->source_name);
        $this->assertSame("#!/bin/bash\nprintf 'prepared only\\n'", $snapshot->fresh()->script_snapshot);
        $this->assertSame(0, ProjectResource::query()->where('project_id', $fixture['project']->getKey())
            ->where('product', 'deployer')->count());
    }

    public function test_recipe_blueprint_replay_rejects_a_changed_exact_core_environment_mapping(): void
    {
        $prepared = $this->preparedSnapshot('blueprint-recipe-replay-remap');
        $alternate = $prepared['fixture']['project']->environments()->create([
            'created_by_user_id' => $prepared['fixture']['actor']->getKey(), 'name' => 'Alternate production',
            'slug' => 'alternate-replay-production', 'environment_type' => 'production', 'status' => 'active',
        ]);
        ProjectResource::query()->where('product', 'deployer')->where('resource_type', 'environment')
            ->where('resource_id', (string) $prepared['nativeEnvironment']->getKey())
            ->update(['environment_id' => $alternate->getKey()]);

        try {
            $prepared['provider']->apply($prepared['attempt']);
            $this->fail('A replay must reject a prepared recipe whose exact Core environment mapping changed.');
        } catch (BlueprintBlocked $exception) {
            $this->assertSame('resource_bindings_changed', $exception->reason);
        }

        $this->assertDatabaseCount('environment_blueprint_recipes', 1, 'deployer');
    }

    public function test_new_canonical_environment_can_receive_a_recipe_snapshot_without_a_server(): void
    {
        $fixture = $this->scenario('blueprint-recipe-new-environment');
        $target = new BlueprintTarget(
            (string) $fixture['actor']->getKey(), (string) $fixture['workspace']->getKey(), (string) $fixture['project']->getKey(),
            [
                'production' => $fixture['target']->environments['production'],
                'staging' => ['id' => null, 'name' => 'Staging', 'type' => 'staging'],
            ],
        );
        $fixture['target'] = $target;
        $recipe = $this->createRecipe($fixture, 'Staging recipe');
        $configuration = $this->configurationWithRecipe($recipe, 'staging');
        $provider = $this->provider();
        $preview = $provider->preview($target, $configuration);
        $this->assertTrue($preview->ready(), implode('; ', $preview->blockers));

        // RequestProjectBlueprint creates canonical environment rows between preview and accepted apply.
        $staging = ProjectEnvironment::query()->create([
            'project_id' => $fixture['project']->getKey(), 'created_by_user_id' => $fixture['actor']->getKey(),
            'name' => 'Staging', 'slug' => 'staging', 'environment_type' => 'staging', 'status' => 'active',
        ]);
        $target = new BlueprintTarget(
            (string) $fixture['actor']->getKey(), (string) $fixture['workspace']->getKey(), (string) $fixture['project']->getKey(),
            [
                'production' => $fixture['target']->environments['production'],
                'staging' => ['id' => (string) $staging->getKey(), 'name' => 'Staging', 'type' => 'staging'],
            ],
        );
        $fixture['target'] = $target;
        $attempt = $this->acceptedAttempt($fixture, $configuration, $preview->authority);

        $result = $provider->apply($attempt);
        $stagingResource = collect($result->resources)->firstWhere('environmentKey', 'staging');
        $nativeEnvironment = Environment::query()->findOrFail($stagingResource->sourceId);
        $snapshot = EnvironmentBlueprintRecipe::query()->sole();

        $this->assertSame((string) $staging->getKey(), (string) $snapshot->canonical_environment_id);
        $this->assertSame((string) $nativeEnvironment->getKey(), (string) $snapshot->environment_id);
        $this->assertSame('staging', $snapshot->environment_key);
        $this->assertNull($nativeEnvironment->server_id);
    }

    public function test_recipe_script_and_published_revision_drift_block_before_the_first_write(): void
    {
        $fixture = $this->scenario('blueprint-recipe-source-drift');
        $recipe = $this->createRecipe($fixture, 'Published source', [
            'is_published' => true, 'published_at' => now()->subHour(), 'gallery_revision_at' => now()->subHour(),
        ]);
        $provider = $this->provider();
        $configuration = $this->configurationWithRecipe($recipe);
        $preview = $provider->preview($fixture['target'], $configuration);
        $attempt = $this->acceptedAttempt($fixture, $configuration, $preview->authority);
        $recipe->forceFill(['script' => 'changed before apply', 'gallery_revision_at' => now()])->save();

        try {
            $provider->apply($attempt);
            $this->fail('A changed recipe script and published revision must invalidate the accepted preview.');
        } catch (BlueprintBlocked $exception) {
            $this->assertSame('native_state_changed', $exception->reason);
        }

        $this->assertDatabaseCount('environment_blueprint_recipes', 0, 'deployer');
        $this->assertDatabaseCount('blueprint_application_receipts', 0, 'deployer');
    }

    public function test_recipe_owner_change_blocks_before_the_first_write(): void
    {
        $fixture = $this->scenario('blueprint-recipe-owner-drift');
        $recipe = $this->createRecipe($fixture, 'Owned recipe');
        $provider = $this->provider();
        $configuration = $this->configurationWithRecipe($recipe);
        $preview = $provider->preview($fixture['target'], $configuration);
        $attempt = $this->acceptedAttempt($fixture, $configuration, $preview->authority);
        $recipe->forceFill(['user_id' => User::factory()->create()->getKey(), 'organization_id' => null])->save();

        try {
            $provider->apply($attempt);
            $this->fail('Recipe ownership drift must invalidate the accepted preview.');
        } catch (BlueprintBlocked $exception) {
            $this->assertSame('native_state_changed', $exception->reason);
        }

        $this->assertDatabaseCount('environment_blueprint_recipes', 0, 'deployer');
    }

    public function test_recipe_preview_fails_closed_when_the_hmac_key_is_missing(): void
    {
        $fixture = $this->scenario('blueprint-recipe-no-key');
        $recipe = $this->createRecipe($fixture, 'Key required recipe');
        $provider = $this->provider();
        $configuration = $this->configurationWithRecipe($recipe);
        $key = config('app.key');
        config(['app.key' => '']);
        try {
            $preview = $provider->preview($fixture['target'], $configuration);
        } finally {
            config(['app.key' => $key]);
        }

        $this->assertFalse($preview->ready());
        $this->assertSame([], $preview->authority);
    }

    public function test_corrupt_encrypted_source_script_blocks_preview_without_exposing_ciphertext_errors(): void
    {
        $fixture = $this->scenario('blueprint-recipe-corrupt-source');
        $recipe = $this->createRecipe($fixture, 'Corrupt source');
        DB::connection('deployer')->table('recipes')->where('id', $recipe->getKey())
            ->update(['script' => 'corrupt-encrypted-source-marker']);

        $preview = $this->provider()->preview($fixture['target'], $this->configurationWithRecipe($recipe));

        $this->assertFalse($preview->ready());
        $this->assertSame([], $preview->authority);
        $this->assertStringNotContainsString('corrupt-encrypted-source-marker', implode(' ', $preview->blockers));
        $this->assertStringNotContainsString('DecryptException', implode(' ', $preview->blockers));
    }

    public function test_recipe_apply_fails_closed_when_snapshot_store_migration_is_unavailable(): void
    {
        $fixture = $this->scenario('blueprint-recipe-store-missing');
        $recipe = $this->createRecipe($fixture, 'Migration-gated recipe');
        $provider = $this->provider();
        $configuration = $this->configurationWithRecipe($recipe);
        $preview = $provider->preview($fixture['target'], $configuration);
        $this->assertTrue($preview->ready(), implode('; ', $preview->blockers));
        $attempt = $this->acceptedAttempt($fixture, $configuration, $preview->authority);
        Schema::connection('deployer')->dropIfExists('environment_blueprint_recipes');

        try {
            $provider->apply($attempt);
            $this->fail('Recipe snapshot apply must wait until the Deployer migration exists.');
        } catch (BlueprintBlocked $exception) {
            $this->assertSame('native_state_changed', $exception->reason);
        }

        $this->assertDatabaseCount('projects', 0, 'deployer');
        $this->assertDatabaseCount('blueprint_application_receipts', 0, 'deployer');
    }

    public function test_recipe_receipt_replays_before_core_resource_recording(): void
    {
        $fixture = $this->scenario('blueprint-recipe-replay-before-core-recording');
        $recipe = $this->createRecipe($fixture, 'Crash recovery recipe');
        $provider = $this->provider();
        $configuration = $this->configurationWithRecipe($recipe);
        $preview = $provider->preview($fixture['target'], $configuration);
        $this->assertTrue($preview->ready(), implode('; ', $preview->blockers));
        $attempt = $this->acceptedAttempt($fixture, $configuration, $preview->authority);

        $first = $provider->apply($attempt);
        $this->assertDatabaseCount('blueprint_application_receipts', 1, 'deployer');
        $this->assertSame(0, ProjectResource::query()->where('project_id', $fixture['project']->getKey())
            ->where('product', 'deployer')->count());

        $replay = $provider->apply($attempt);

        $this->assertSame($first->toArray(), $replay->toArray());
        $this->assertSame(0, ProjectResource::query()->where('project_id', $fixture['project']->getKey())
            ->where('product', 'deployer')->count());

        DB::connection('core')->transaction(function () use ($attempt, $replay): void {
            app(RecordBlueprintResources::class)->handle($attempt, $replay);
        });

        $nativeProject = NativeProject::query()->where('organization_id', $fixture['organization']->getKey())->sole();
        $nativeEnvironment = Environment::query()->where('project_id', $nativeProject->getKey())->sole();
        $this->assertSame(1, NativeProject::query()->where('organization_id', $fixture['organization']->getKey())->count());
        $this->assertSame(1, Environment::query()->where('project_id', $nativeProject->getKey())->count());
        $this->assertSame(1, EnvironmentBlueprintRecipe::query()->where('step_id', $attempt->stepId)->count());
        $this->assertDatabaseCount('blueprint_application_receipts', 1, 'deployer');

        $this->assertSame(2, ProjectResource::query()->where('project_id', $fixture['project']->getKey())
            ->where('product', 'deployer')->count());
        $projectMapping = ProjectResource::query()->where('project_id', $fixture['project']->getKey())
            ->where('product', 'deployer')->where('resource_type', 'project')->sole();
        $environmentMapping = ProjectResource::query()->where('project_id', $fixture['project']->getKey())
            ->where('product', 'deployer')->where('resource_type', 'environment')->sole();
        $this->assertSame((string) $nativeProject->getKey(), (string) $projectMapping->resource_id);
        $this->assertNull($projectMapping->environment_id);
        $this->assertSame((string) $nativeEnvironment->getKey(), (string) $environmentMapping->resource_id);
        $this->assertSame((string) $fixture['environment']->getKey(), (string) $environmentMapping->environment_id);
        $this->assertSame('active', $projectMapping->status);
        $this->assertSame('active', $environmentMapping->status);
    }

    public function test_recipe_receipt_replays_for_mapped_project_with_new_unmapped_environment(): void
    {
        $fixture = $this->scenario('blueprint-recipe-replay-mapped-project-unmapped-environment');
        $nativeProject = NativeProject::query()->create([
            'organization_id' => $fixture['organization']->getKey(), 'created_by' => $fixture['nativeActor']->getKey(),
            'name' => 'Checkout', 'slug' => 'mapped-checkout',
        ]);
        ProjectResource::query()->create([
            'project_id' => $fixture['project']->getKey(), 'environment_id' => null,
            'product' => 'deployer', 'resource_type' => 'project', 'resource_id' => (string) $nativeProject->getKey(),
            'name' => $nativeProject->name, 'status' => 'active',
        ]);
        $recipe = $this->createRecipe($fixture, 'New environment recipe');
        $provider = $this->provider();
        $configuration = $this->configurationWithRecipe($recipe);
        $preview = $provider->preview($fixture['target'], $configuration);
        $this->assertTrue($preview->ready(), implode('; ', $preview->blockers));
        $acceptedEnvironment = collect($preview->authority['environments'])->firstWhere('key', 'production');
        $this->assertNotNull($acceptedEnvironment);
        $this->assertNull($acceptedEnvironment['mapping_id']);
        $this->assertNull($acceptedEnvironment['source_id']);
        $attempt = $this->acceptedAttempt($fixture, $configuration, $preview->authority);

        $first = $provider->apply($attempt);
        $this->assertSame((string) $nativeProject->getKey(), (string) $first->resources[0]->sourceId);
        $this->assertSame(1, ProjectResource::query()->where('project_id', $fixture['project']->getKey())
            ->where('product', 'deployer')->where('resource_type', 'project')->count());
        $this->assertSame(0, ProjectResource::query()->where('project_id', $fixture['project']->getKey())
            ->where('product', 'deployer')->where('resource_type', 'environment')->count());

        $replay = $provider->apply($attempt);

        $this->assertSame($first->toArray(), $replay->toArray());
        $this->assertSame(1, Environment::query()->where('project_id', $nativeProject->getKey())->count());
        $this->assertSame(1, EnvironmentBlueprintRecipe::query()->where('step_id', $attempt->stepId)->count());
        $this->assertSame(0, ProjectResource::query()->where('project_id', $fixture['project']->getKey())
            ->where('product', 'deployer')->where('resource_type', 'environment')->count());
    }

    public function test_recipe_replay_rejects_a_foreign_claim_on_the_canonical_environment(): void
    {
        $fixture = $this->scenario('blueprint-recipe-replay-foreign-environment-claim');
        $recipe = $this->createRecipe($fixture, 'Foreign claim recipe');
        $provider = $this->provider();
        $configuration = $this->configurationWithRecipe($recipe);
        $preview = $provider->preview($fixture['target'], $configuration);
        $this->assertTrue($preview->ready(), implode('; ', $preview->blockers));
        $attempt = $this->acceptedAttempt($fixture, $configuration, $preview->authority);
        $first = $provider->apply($attempt);
        $foreignProject = CoreProject::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(), 'created_by_user_id' => $fixture['actor']->getKey(),
            'name' => 'Foreign project', 'slug' => 'foreign-environment-claim', 'status' => 'active',
        ]);
        ProjectResource::query()->create([
            'project_id' => $foreignProject->getKey(),
            'environment_id' => $fixture['environment']->getKey(),
            'product' => 'deployer', 'resource_type' => 'environment', 'resource_id' => 'foreign-native-environment',
            'name' => 'Foreign environment', 'status' => 'active',
        ]);

        try {
            $provider->apply($attempt);
            $this->fail('A foreign project claim on the canonical environment must block snapshot replay.');
        } catch (BlueprintBlocked $exception) {
            $this->assertSame('resource_conflict', $exception->reason);
        }

        $this->assertSame(1, NativeProject::query()->where('organization_id', $fixture['organization']->getKey())->count());
        $this->assertSame(1, Environment::query()->where('project_id', $first->resources[0]->sourceId)->count());
        $this->assertSame(1, EnvironmentBlueprintRecipe::query()->where('step_id', $attempt->stepId)->count());
        $this->assertDatabaseCount('blueprint_application_receipts', 1, 'deployer');
    }

    public function test_saved_snapshot_copy_is_idempotent_survives_source_deletion_and_keeps_edits(): void
    {
        $prepared = $this->preparedSnapshot('blueprint-copy-idempotent');
        $prepared['recipe']->delete();
        $snapshot = $prepared['snapshot']->fresh();
        $action = app(InstallBlueprintRecipeSnapshotAction::class);

        $copy = $action->handle(
            $prepared['fixture']['nativeActor'], $prepared['nativeProject'], $prepared['nativeEnvironment'], $snapshot,
        );
        $this->assertSame($prepared['fixture']['organization']->getKey(), $copy->organization_id);
        $this->assertSame($prepared['fixture']['nativeActor']->getKey(), $copy->user_id);
        $this->assertSame('Reviewed workspace recipe', $copy->name);
        $this->assertSame("#!/bin/bash\nprintf 'prepared only\\n'", $copy->script);
        $this->assertFalse($copy->is_published);
        $this->assertNull($copy->source_recipe_id);
        $this->assertNull($copy->source_revision_at);
        $this->assertDatabaseCount('recipe_server', 0, 'deployer');

        $copy->forceFill(['name' => 'Edited after copy', 'script' => 'operator edit'])->save();
        $replay = $action->handle(
            $prepared['fixture']['nativeActor'], $prepared['nativeProject'], $prepared['nativeEnvironment'], $snapshot->fresh(),
        );

        $this->assertSame($copy->getKey(), $replay->getKey());
        $this->assertSame('Edited after copy', $replay->name);
        $this->assertSame('operator edit', $replay->script);
        $this->assertDatabaseCount('recipes', 1, 'deployer');
        $this->assertSame($copy->getKey(), $snapshot->fresh()->installed_recipe_id);
        $this->assertNotNull($snapshot->fresh()->install_receipt_fingerprint);
    }

    public function test_tampered_snapshot_install_receipt_blocks_replay_without_creating_another_copy(): void
    {
        $prepared = $this->preparedSnapshot('blueprint-copy-tampered-receipt');
        $action = app(InstallBlueprintRecipeSnapshotAction::class);
        $copy = $action->handle(
            $prepared['fixture']['nativeActor'], $prepared['nativeProject'], $prepared['nativeEnvironment'], $prepared['snapshot'],
        );

        DB::connection('deployer')->table('environment_blueprint_recipes')
            ->where('id', $prepared['snapshot']->getKey())
            ->update(['installed_recipe_id' => (int) $copy->getKey() + 1]);

        try {
            $action->handle(
                $prepared['fixture']['nativeActor'], $prepared['nativeProject'], $prepared['nativeEnvironment'], $prepared['snapshot']->fresh(),
            );
            $this->fail('A tampered install receipt must not resolve or create another recipe copy.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertDatabaseCount('recipes', 1, 'deployer');
    }

    public function test_personal_snapshot_requires_owner_and_explicit_workspace_sharing_consent(): void
    {
        $prepared = $this->preparedSnapshot('blueprint-copy-personal', personal: true);
        $action = app(InstallBlueprintRecipeSnapshotAction::class);
        try {
            $action->handle(
                $prepared['fixture']['nativeActor'], $prepared['nativeProject'], $prepared['nativeEnvironment'], $prepared['snapshot'],
            );
            $this->fail('A personal recipe snapshot cannot be copied into the shared workspace without consent.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        $this->assertNull($prepared['snapshot']->fresh()->installed_recipe_id);

        $otherActor = $this->addNativeProjectMember($prepared['fixture']);
        try {
            $action->handle(
                $otherActor, $prepared['nativeProject'], $prepared['nativeEnvironment'], $prepared['snapshot'], true,
            );
            $this->fail('A teammate cannot copy a personal snapshot owned by another native actor.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertNull($prepared['snapshot']->fresh()->installed_recipe_id);

        $copy = $action->handle(
            $prepared['fixture']['nativeActor'], $prepared['nativeProject'], $prepared['nativeEnvironment'], $prepared['snapshot'], true,
        );
        $this->assertSame($prepared['fixture']['organization']->getKey(), $copy->organization_id);
        $this->assertSame($prepared['fixture']['nativeActor']->getKey(), $copy->user_id);
        $this->assertNull($copy->source_recipe_id);
    }

    public function test_snapshot_copy_rechecks_core_grant_and_exact_current_environment_mapping(): void
    {
        $prepared = $this->preparedSnapshot('blueprint-copy-authority');
        $grant = WorkspaceProductAccess::query()->where('membership_id', $prepared['fixture']['membership']->getKey())
            ->where('product', 'deployer')->sole();
        $grant->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();
        try {
            app(InstallBlueprintRecipeSnapshotAction::class)->handle(
                $prepared['fixture']['nativeActor'], $prepared['nativeProject'], $prepared['nativeEnvironment'], $prepared['snapshot'],
            );
            $this->fail('A revoked current Core workspace grant must block the copy.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $grant->forceFill(['status' => 'active', 'revoked_at' => null])->save();

        $alternateEnvironment = $prepared['fixture']['project']->environments()->create([
            'created_by_user_id' => $prepared['fixture']['actor']->getKey(), 'name' => 'Alternate production',
            'slug' => 'alternate-production', 'environment_type' => 'production', 'status' => 'active',
        ]);
        ProjectResource::query()->where('resource_type', 'environment')->where('resource_id', $prepared['nativeEnvironment']->getKey())
            ->update(['environment_id' => $alternateEnvironment->getKey()]);
        try {
            app(InstallBlueprintRecipeSnapshotAction::class)->handle(
                $prepared['fixture']['nativeActor'], $prepared['nativeProject'], $prepared['nativeEnvironment'], $prepared['snapshot'],
            );
            $this->fail('A remapped canonical environment must not authorize a stale snapshot copy.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertNull($prepared['snapshot']->fresh()->installed_recipe_id);
    }

    public function test_project_page_filters_snapshot_rows_and_counts_by_source_owner_and_current_mapping(): void
    {
        $prepared = $this->preparedSnapshot('blueprint-page-visibility', personal: true);
        $workspaceSnapshot = $this->createVisibleOrganizationSnapshot($prepared, 'Workspace recipe metadata');

        $ownerResponse = $this->actingAs($prepared['fixture']['actor'], 'platform')
            ->get(route('projects.show', $prepared['nativeProject']));
        $ownerResponse->assertOk()->assertSee('Reviewed workspace recipe')->assertSee('Workspace recipe metadata');
        $ownerResponse->assertSee('2 recipe references were prepared');

        $otherActor = $this->addNativeProjectMember($prepared['fixture']);
        $viewerResponse = $this->actingAs($this->platformActorFor($otherActor), 'platform')
            ->get(route('projects.show', $prepared['nativeProject']));
        $viewerResponse->assertOk()->assertSee('Workspace recipe metadata')->assertDontSee('Reviewed workspace recipe');
        $viewerResponse->assertSee('1 recipe reference was prepared');

        $alternateEnvironment = $prepared['fixture']['project']->environments()->create([
            'created_by_user_id' => $prepared['fixture']['actor']->getKey(), 'name' => 'Remapped production',
            'slug' => 'remapped-production', 'environment_type' => 'production', 'status' => 'active',
        ]);
        ProjectResource::query()->where('resource_type', 'environment')->where('resource_id', $prepared['nativeEnvironment']->getKey())
            ->update(['environment_id' => $alternateEnvironment->getKey()]);
        $remappedResponse = $this->actingAs($prepared['fixture']['actor'], 'platform')
            ->get(route('projects.show', $prepared['nativeProject']));
        $remappedResponse->assertOk()->assertDontSee('Reviewed workspace recipe')->assertDontSee('Workspace recipe metadata');

        Schema::connection('deployer')->dropIfExists('environment_blueprint_recipes');
        config(['platform.products.deployer.auth_authority' => 'legacy']);
        $legacyResponse = $this->actingAs($prepared['fixture']['nativeActor'], 'web')
            ->get(route('projects.show', $prepared['nativeProject']));
        $legacyResponse->assertOk()->assertDontSee('Reviewed workspace recipe')->assertDontSee('Workspace recipe metadata');
        $this->assertSame(1, $workspaceSnapshot->position);
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
            'definition' => ['environments' => collect($fixture['target']->environments)->map(fn (array $environment, string $key): array => [
                'key' => $key, 'name' => $environment['name'], 'type' => $environment['type'],
            ])->values()->all(), 'products' => ['deployer' => $configuration]],
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

    /** @param array<string, mixed> $fixture @param array<string, mixed> $attributes */
    private function createRecipe(array $fixture, string $name, array $attributes = []): Recipe
    {
        $recipe = new Recipe;
        $recipe->forceFill(array_merge([
            'user_id' => $fixture['nativeActor']->getKey(), 'organization_id' => $fixture['organization']->getKey(),
            'name' => $name, 'description' => null, 'script' => 'echo prepared', 'is_published' => false,
            'published_at' => null, 'gallery_revision_at' => null, 'source_recipe_id' => null, 'source_revision_at' => null,
        ], $attributes));
        $recipe->save();

        return $recipe;
    }

    /** @return array<string, mixed> */
    private function preparedSnapshot(string $slug, bool $personal = false): array
    {
        $fixture = $this->scenario($slug);
        $recipe = $this->createRecipe($fixture, 'Reviewed workspace recipe', [
            'script' => "#!/bin/bash\nprintf 'prepared only\\n'",
        ]);
        if ($personal) {
            $recipe->forceFill(['organization_id' => null])->save();
        }
        $configuration = $this->configurationWithRecipe($recipe);
        $provider = $this->provider();
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
            'fixture' => $fixture, 'recipe' => $recipe, 'provider' => $provider, 'configuration' => $configuration,
            'attempt' => $attempt, 'result' => $result, 'nativeProject' => $nativeProject,
            'nativeEnvironment' => $nativeEnvironment,
            'snapshot' => EnvironmentBlueprintRecipe::query()->where('step_id', $attempt->stepId)->sole(),
        ];
    }

    /** @param array<string, mixed> $prepared */
    private function createVisibleOrganizationSnapshot(array $prepared, string $name): EnvironmentBlueprintRecipe
    {
        return EnvironmentBlueprintRecipe::query()->create([
            'step_id' => (string) Str::ulid(), 'actor_source_id' => $prepared['fixture']['nativeActor']->getKey(),
            'workspace_source_id' => $prepared['fixture']['organization']->getKey(),
            'canonical_project_id' => $prepared['fixture']['target']->projectId,
            'canonical_environment_id' => $prepared['fixture']['target']->environments['production']['id'],
            'environment_key' => 'production', 'environment_id' => $prepared['nativeEnvironment']->getKey(),
            'source_recipe_id' => 987654321, 'source_user_id' => null,
            'source_organization_id' => $prepared['fixture']['organization']->getKey(),
            'source_gallery_recipe_id' => null, 'source_name' => $name, 'source_description' => null,
            'source_is_published' => false, 'source_updated_at' => now(), 'source_revision_at' => null,
            'source_published_at' => null, 'source_gallery_revision_at' => null,
            'script_snapshot' => 'echo stored', 'script_fingerprint' => str_repeat('a', 64),
            'binding_fingerprint' => str_repeat('b', 64), 'position' => 1,
        ]);
    }

    /** @param array<string, mixed> $fixture */
    private function addNativeProjectMember(array $fixture): User
    {
        $nativeActor = User::factory()->create();
        $fixture['organization']->members()->attach($nativeActor, ['role' => 'developer']);
        $nativeActor->forceFill([
            'current_organization_id' => $fixture['organization']->getKey(), 'email_verified_at' => now(),
        ])->save();
        $email = 'teammate-'.Str::lower(Str::random(8)).'@example.test';
        $platformActor = PlatformUser::query()->forceCreate([
            'id' => (string) Str::ulid(), 'name' => 'Platform teammate', 'email' => $email,
            'email_normalized' => $email, 'status' => 'active', 'email_verified_at' => now(),
        ]);
        LegacyIdentityMap::query()->create([
            'source_product' => 'deployer', 'source_entity' => 'user', 'source_id' => (string) $nativeActor->getKey(),
            'canonical_entity' => 'user', 'canonical_id' => (string) $platformActor->getKey(), 'status' => 'reconciled',
        ]);
        $membership = WorkspaceMembership::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(), 'user_id' => $platformActor->getKey(),
            'role' => 'developer', 'status' => 'active',
        ]);
        WorkspaceProductAccess::query()->create([
            'membership_id' => $membership->getKey(), 'product' => 'deployer', 'role' => 'developer', 'status' => 'active',
        ]);
        ProjectMembership::query()->create([
            'project_id' => $fixture['project']->getKey(), 'user_id' => $platformActor->getKey(),
            'role' => 'developer', 'status' => 'active',
        ]);

        return $nativeActor;
    }

    private function platformActorFor(User $nativeActor): PlatformUser
    {
        $identity = LegacyIdentityMap::query()->where('source_product', 'deployer')->where('source_entity', 'user')
            ->where('source_id', (string) $nativeActor->getKey())->sole();

        return PlatformUser::query()->findOrFail($identity->canonical_id);
    }

    /** @return array<string, mixed> */
    private function configurationWithRecipe(Recipe $recipe, string $environment = 'production'): array
    {
        return [
            ...$this->configuration(),
            'environment_recipes' => [['environment' => $environment, 'recipe_ids' => [(int) $recipe->getKey()]]],
        ];
    }
}
