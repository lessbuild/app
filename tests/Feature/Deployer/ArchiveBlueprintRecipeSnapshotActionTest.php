<?php

namespace Tests\Feature\Deployer;

use App\Core\Data\Blueprints\BlueprintStepAttempt;
use App\Core\Data\Blueprints\BlueprintTarget;
use App\Core\Data\Deletion\ProductDeletionAttempt;
use App\Core\Data\Deletion\ProductDeletionTarget;
use App\Core\Models\DeletionStep;
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
use App\Core\Services\Deletion\DeletionAuthority;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Deployer\Actions\Project\ApplyApplicationTemplate;
use App\Modules\Deployer\Actions\Project\ArchiveBlueprintRecipeSnapshotAction;
use App\Modules\Deployer\Actions\Project\InstallBlueprintRecipeSnapshotAction;
use App\Modules\Deployer\Models\BlueprintApplicationReceipt;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\EnvironmentBlueprintRecipe;
use App\Modules\Deployer\Models\EnvironmentBlueprintRecipeTombstone;
use App\Modules\Deployer\Models\ProductDeletionFence;
use App\Modules\Deployer\Models\Project as NativeProject;
use App\Modules\Deployer\Models\Recipe;
use App\Modules\Deployer\Models\Server;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\ApplicationTemplateCatalog;
use App\Modules\Deployer\Services\Core\DeployerProductDeletionProvider;
use App\Modules\Deployer\Services\Core\DeployerProjectBlueprintProvider;
use App\Modules\Deployer\Services\Entitlements;
use App\Modules\Deployer\Services\EnvironmentBlueprintRecipeTombstoneEvidence;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Focused coverage for terminal, private, manager-authorized recipe-reference archival. */
final class ArchiveBlueprintRecipeSnapshotActionTest extends TestCase
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
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        ]);
    }

    public function test_archive_erases_corrupt_ciphertext_and_keeps_only_repeatable_opaque_evidence(): void
    {
        $prepared = $this->preparedPersonalSnapshot('archive-private-reference');
        $manager = $this->managerFor($prepared['fixture'], 'archive-manager');
        DB::connection('deployer')->table('environment_blueprint_recipes')
            ->where('id', $prepared['snapshot']->getKey())->update(['script_snapshot' => 'corrupt-ciphertext']);

        $page = $this->actingAs($manager['platform'], 'platform')->get(route('projects.show', $prepared['nativeProject']));
        $page->assertOk()
            ->assertSee('Private personal recipe reference')
            ->assertDontSee('PRIVATE RECIPE TITLE 98f24')
            ->assertDontSee('PRIVATE SCRIPT BODY 98f24');

        $step = ProjectBlueprintStep::query()->findOrFail($prepared['attempt']->stepId);
        $receipt = BlueprintApplicationReceipt::query()->where('step_id', $step->getKey())->sole();
        $result = app(ArchiveBlueprintRecipeSnapshotAction::class)->handle(
            $manager['native'], $prepared['nativeProject'], $prepared['nativeEnvironment'], (string) $step->getKey(), 0, true,
        );

        $this->assertFalse($result['already_archived']);
        $this->assertDatabaseMissing('environment_blueprint_recipes', ['id' => $prepared['snapshot']->getKey()], 'deployer');
        $row = DB::connection('deployer')->table('environment_blueprint_recipe_tombstones')
            ->where('step_id', $step->getKey())->where('environment_id', $prepared['nativeEnvironment']->getKey())
            ->where('position', 0)->first();
        $this->assertNotNull($row);
        $this->assertSameCanonicalizing([
            'id', 'step_id', 'environment_id', 'position', 'archived_at', 'signature_version', 'signing_key_id',
            'slot_commitment', 'tombstone_signature',
        ], array_keys((array) $row));
        $tombstone = EnvironmentBlueprintRecipeTombstone::query()->findOrFail($row->id);
        $this->assertTrue(app(EnvironmentBlueprintRecipeTombstoneEvidence::class)->verifyTombstone($tombstone, $step, $receipt));

        $audit = $manager['native']->events()->latest('id')->firstOrFail();
        $this->assertSame('A prepared recipe reference was archived.', $audit->event);
        $this->assertSame((string) $tombstone->getKey(), (string) $audit->parentable_id);
        $this->assertInstanceOf(EnvironmentBlueprintRecipeTombstone::class, $audit->parentable);
        $this->assertStringNotContainsString('PRIVATE RECIPE TITLE 98f24', $audit->event);
        $this->assertStringNotContainsString('PRIVATE SCRIPT BODY 98f24', $audit->event);

        $repeat = app(ArchiveBlueprintRecipeSnapshotAction::class)->handle(
            $manager['native'], $prepared['nativeProject'], $prepared['nativeEnvironment'], (string) $step->getKey(), 0, true,
        );
        $this->assertTrue($repeat['already_archived']);
        $this->assertSame(1, EnvironmentBlueprintRecipeTombstone::query()->where('step_id', $step->getKey())->count());

        $this->assertConflict(fn () => app(InstallBlueprintRecipeSnapshotAction::class)->handle(
            $prepared['fixture']['nativeActor'], $prepared['nativeProject'], $prepared['nativeEnvironment'], $prepared['snapshot'], true,
        ));
        $this->assertDatabaseMissing('environment_blueprint_recipes', ['id' => $prepared['snapshot']->getKey()], 'deployer');
    }

    public function test_stale_core_mapping_and_deletion_fence_block_archive(): void
    {
        $prepared = $this->preparedPersonalSnapshot('archive-stale-mapping');
        ProjectResource::query()->where('resource_type', 'environment')
            ->where('resource_id', (string) $prepared['nativeEnvironment']->getKey())
            ->update(['status' => 'inactive']);

        $this->assertConflict(fn () => app(ArchiveBlueprintRecipeSnapshotAction::class)->handle(
            $prepared['fixture']['nativeActor'], $prepared['nativeProject'], $prepared['nativeEnvironment'],
            (string) $prepared['attempt']->stepId, 0, true,
        ));
        $this->assertDatabaseHas('environment_blueprint_recipes', ['id' => $prepared['snapshot']->getKey()], 'deployer');
        $this->assertDatabaseCount('environment_blueprint_recipe_tombstones', 0, 'deployer');

        $fenced = $this->preparedPersonalSnapshot('archive-deletion-fence');
        ProductDeletionFence::query()->create([
            'kind' => 'workspace', 'source_id' => (string) $fenced['fixture']['organization']->getKey(),
            'request_id' => 'archive-fence-'.Str::lower(Str::random(8)), 'payload_hash' => str_repeat('c', 64),
            'generation' => 1, 'state' => 'prepared',
        ]);
        $this->assertConflict(fn () => app(ArchiveBlueprintRecipeSnapshotAction::class)->handle(
            $fenced['fixture']['nativeActor'], $fenced['nativeProject'], $fenced['nativeEnvironment'],
            (string) $fenced['attempt']->stepId, 0, true,
        ));
        $this->assertDatabaseHas('environment_blueprint_recipes', ['id' => $fenced['snapshot']->getKey()], 'deployer');
        $this->assertDatabaseCount('environment_blueprint_recipe_tombstones', 0, 'deployer');
    }

    public function test_duplicate_receipt_project_resource_blocks_archive(): void
    {
        $prepared = $this->preparedPersonalSnapshot('archive-tampered-receipt-project');
        $receipt = BlueprintApplicationReceipt::query()->where('step_id', $prepared['attempt']->stepId)->sole();
        $result = $receipt->result;
        $projectResource = collect($result['resources'])->first(fn (array $resource): bool => $resource['type'] === 'project');
        $this->assertNotNull($projectResource);
        $result['resources'][] = $projectResource;
        $receipt->forceFill(['result' => $result])->save();

        $this->assertConflict(fn () => app(ArchiveBlueprintRecipeSnapshotAction::class)->handle(
            $prepared['fixture']['nativeActor'], $prepared['nativeProject'], $prepared['nativeEnvironment'],
            (string) $prepared['attempt']->stepId, 0, true,
        ));
        $this->assertDatabaseHas('environment_blueprint_recipes', ['id' => $prepared['snapshot']->getKey()], 'deployer');
        $this->assertDatabaseCount('environment_blueprint_recipe_tombstones', 0, 'deployer');
    }

    public function test_unexpected_receipt_environment_resource_blocks_archive(): void
    {
        $prepared = $this->preparedPersonalSnapshot('archive-unexpected-receipt-resource');
        $receipt = BlueprintApplicationReceipt::query()->where('step_id', $prepared['attempt']->stepId)->sole();
        $result = $receipt->result;
        $unexpected = $result['resources'][1];
        $unexpected['environmentKey'] = 'unexpected';
        $result['resources'][] = $unexpected;
        $receipt->forceFill(['result' => $result])->save();

        $this->assertConflict(fn () => app(ArchiveBlueprintRecipeSnapshotAction::class)->handle(
            $prepared['fixture']['nativeActor'], $prepared['nativeProject'], $prepared['nativeEnvironment'],
            (string) $prepared['attempt']->stepId, 0, true,
        ));
        $this->assertDatabaseHas('environment_blueprint_recipes', ['id' => $prepared['snapshot']->getKey()], 'deployer');
        $this->assertDatabaseCount('environment_blueprint_recipe_tombstones', 0, 'deployer');
    }

    public function test_each_environment_can_archive_its_own_position_zero_slot(): void
    {
        $prepared = $this->preparedPersonalSnapshot('archive-two-environments', secondEnvironment: true);
        $snapshots = EnvironmentBlueprintRecipe::query()->where('step_id', $prepared['attempt']->stepId)
            ->orderBy('environment_key')->get()->keyBy('environment_key');
        $this->assertCount(2, $snapshots);

        $production = app(ArchiveBlueprintRecipeSnapshotAction::class)->handle(
            $prepared['fixture']['nativeActor'], $prepared['nativeProject'], $prepared['nativeEnvironment'],
            (string) $prepared['attempt']->stepId, 0, true,
        );
        $this->assertFalse($production['already_archived']);
        $this->assertDatabaseMissing('environment_blueprint_recipes', ['id' => $snapshots['production']->getKey()], 'deployer');
        $this->assertDatabaseHas('environment_blueprint_recipes', ['id' => $snapshots['staging']->getKey()], 'deployer');

        $staging = app(ArchiveBlueprintRecipeSnapshotAction::class)->handle(
            $prepared['fixture']['nativeActor'], $prepared['nativeProject'], $prepared['stagingEnvironment'],
            (string) $prepared['attempt']->stepId, 0, true,
        );
        $this->assertFalse($staging['already_archived']);
        $this->assertDatabaseCount('environment_blueprint_recipes', 0, 'deployer');
        $this->assertDatabaseCount('environment_blueprint_recipe_tombstones', 2, 'deployer');
    }

    public function test_duplicate_active_slot_rows_and_tombstone_snapshot_overlap_fail_closed(): void
    {
        $duplicated = $this->preparedPersonalSnapshot('archive-duplicate-active-slot');
        $second = $duplicated['snapshot']->replicate();
        $second->forceFill(['source_recipe_id' => (int) $duplicated['snapshot']->source_recipe_id + 100000]);
        $second->save();

        $this->assertConflict(fn () => app(ArchiveBlueprintRecipeSnapshotAction::class)->handle(
            $duplicated['fixture']['nativeActor'], $duplicated['nativeProject'], $duplicated['nativeEnvironment'],
            (string) $duplicated['attempt']->stepId, 0, true,
        ));
        $this->assertSame(2, EnvironmentBlueprintRecipe::query()->where('step_id', $duplicated['attempt']->stepId)
            ->where('environment_id', $duplicated['nativeEnvironment']->getKey())->where('position', 0)->count());
        $this->assertDatabaseCount('environment_blueprint_recipe_tombstones', 0, 'deployer');

        $overlap = $this->preparedPersonalSnapshot('archive-tombstone-active-overlap');
        $step = ProjectBlueprintStep::query()->findOrFail($overlap['attempt']->stepId);
        $receipt = BlueprintApplicationReceipt::query()->where('step_id', $step->getKey())->sole();
        $tombstone = app(EnvironmentBlueprintRecipeTombstoneEvidence::class)
            ->makeTombstone($step, $receipt, $overlap['snapshot']);
        $tombstone->save();

        $this->assertConflict(fn () => app(ArchiveBlueprintRecipeSnapshotAction::class)->handle(
            $overlap['fixture']['nativeActor'], $overlap['nativeProject'], $overlap['nativeEnvironment'],
            (string) $overlap['attempt']->stepId, 0, true,
        ));
        $this->assertDatabaseHas('environment_blueprint_recipes', ['id' => $overlap['snapshot']->getKey()], 'deployer');
        $this->assertSame(1, EnvironmentBlueprintRecipeTombstone::query()->where('step_id', $step->getKey())->count());
    }

    public function test_archive_preserves_installed_copy_owner_and_server_assignment(): void
    {
        $prepared = $this->preparedPersonalSnapshot('archive-installed-copy-custody');
        $copy = app(InstallBlueprintRecipeSnapshotAction::class)->handle(
            $prepared['fixture']['nativeActor'], $prepared['nativeProject'], $prepared['nativeEnvironment'], $prepared['snapshot'], true,
        );
        $server = Server::query()->create([
            'user_id' => $prepared['fixture']['nativeActor']->getKey(),
            'organization_id' => $prepared['fixture']['organization']->getKey(),
            'name' => 'Archive custody fixture', 'type' => 'app',
        ]);
        $copy->servers()->attach($server->getKey(), ['position' => 4]);
        $manager = $this->managerFor($prepared['fixture'], 'archive-copy-manager');

        $response = $this->actingAs($manager['platform'], 'platform')->post(
            route('projects.environments.blueprint-recipes.archive', [$prepared['nativeProject'], $prepared['nativeEnvironment']]),
            ['step_id' => (string) $prepared['attempt']->stepId, 'position' => 0, 'confirm_archive' => '1'],
        );
        $response->assertRedirect(route('projects.show', $prepared['nativeProject']))
            ->assertSessionHas('info', 'Archiving removes this reference only. Any installed copy remains separately and may still need an ownership transfer before account deletion.');

        $retainedCopy = Recipe::query()->findOrFail($copy->getKey());
        $this->assertSame((string) $prepared['fixture']['nativeActor']->getKey(), (string) $retainedCopy->user_id);
        $this->assertSame((string) $prepared['fixture']['organization']->getKey(), (string) $retainedCopy->organization_id);
        $this->assertTrue($retainedCopy->servers()->whereKey($server->getKey())->exists());
        $this->assertSame(4, (int) $retainedCopy->servers()->whereKey($server->getKey())->firstOrFail()->pivot->position);
        $this->assertDatabaseMissing('environment_blueprint_recipes', ['id' => $prepared['snapshot']->getKey()], 'deployer');
    }

    public function test_account_deletion_prepare_recovers_after_the_only_foreign_snapshot_is_archived(): void
    {
        $prepared = $this->preparedPersonalSnapshot('archive-deletion-recovery', foreignWorkspace: true);
        $this->prepareForeignWorkspaceForActorDeletion($prepared);
        $target = $this->accountDeletionTarget($prepared['fixture']['nativeActor']);
        $attempt = $this->accountDeletionAttempt($target, 'archive-deletion-recovery');
        $this->mock(DeletionAuthority::class)->shouldReceive('assertAttempt')->atLeast()->once()->andReturn(new DeletionStep);
        $deletions = app(DeployerProductDeletionProvider::class);

        $blocked = $deletions->prepare($attempt);
        $this->assertSame('blocked', $blocked->status);
        $this->assertSame('deployer_account_has_foreign_owned_records', $blocked->reasonCode);
        $this->assertDatabaseMissing('product_deletion_fences', [
            'kind' => 'account', 'source_id' => (string) $prepared['fixture']['nativeActor']->getKey(),
        ], 'deployer');

        $manager = $this->managerFor($prepared['fixture'], 'archive-deletion-recovery-manager');
        $archived = app(ArchiveBlueprintRecipeSnapshotAction::class)->handle(
            $manager['native'], $prepared['nativeProject'], $prepared['nativeEnvironment'],
            (string) $prepared['attempt']->stepId, 0, true,
        );
        $this->assertFalse($archived['already_archived']);
        $this->assertDatabaseMissing('environment_blueprint_recipes', ['id' => $prepared['snapshot']->getKey()], 'deployer');

        $recovered = $deletions->prepare($attempt);
        $this->assertSame('ready', $recovered->status);
        $this->assertDatabaseHas('product_deletion_receipts', [
            'step_id' => $attempt->stepId, 'phase' => 'prepare', 'status' => 'ready',
        ], 'deployer');
        $this->assertDatabaseHas('product_deletion_fences', [
            'kind' => 'account', 'source_id' => (string) $prepared['fixture']['nativeActor']->getKey(),
            'state' => 'prepared',
        ], 'deployer');
    }

    public function test_archived_snapshot_no_longer_blocks_account_deletion_but_installed_copy_does(): void
    {
        $prepared = $this->preparedPersonalSnapshot('archive-deletion-installed-copy', foreignWorkspace: true);
        $copy = app(InstallBlueprintRecipeSnapshotAction::class)->handle(
            $prepared['fixture']['nativeActor'], $prepared['nativeProject'], $prepared['nativeEnvironment'], $prepared['snapshot'], true,
        );
        $this->prepareForeignWorkspaceForActorDeletion($prepared);
        $target = $this->accountDeletionTarget($prepared['fixture']['nativeActor']);
        $deletions = app(DeployerProductDeletionProvider::class);
        $before = $deletions->inspect($target);
        $this->assertContains('deployer_account_has_foreign_owned_records', $before->blockers);

        $manager = $this->managerFor($prepared['fixture'], 'archive-copy-deletion-manager');
        app(ArchiveBlueprintRecipeSnapshotAction::class)->handle(
            $manager['native'], $prepared['nativeProject'], $prepared['nativeEnvironment'],
            (string) $prepared['attempt']->stepId, 0, true,
        );
        $this->assertDatabaseMissing('environment_blueprint_recipes', ['id' => $prepared['snapshot']->getKey()], 'deployer');
        $this->assertDatabaseHas('recipes', [
            'id' => $copy->getKey(), 'user_id' => $prepared['fixture']['nativeActor']->getKey(),
            'organization_id' => $prepared['fixture']['organization']->getKey(),
        ], 'deployer');
        $afterArchive = $deletions->inspect($target);
        $this->assertContains('deployer_account_has_foreign_owned_records', $afterArchive->blockers);

        Recipe::query()->whereKey($copy->getKey())->delete();
        $afterCopyRemoval = $deletions->inspect($target);
        $this->assertNotContains('deployer_account_has_foreign_owned_records', $afterCopyRemoval->blockers);
    }

    private function assertConflict(\Closure $operation): void
    {
        try {
            $operation();
            $this->fail('The archive request should have failed closed with HTTP 409.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
    }

    /** @return array<string, mixed> */
    private function preparedPersonalSnapshot(string $slug, bool $foreignWorkspace = false, bool $secondEnvironment = false): array
    {
        $fixture = $this->scenario($slug, $foreignWorkspace, $secondEnvironment);
        $recipe = new Recipe;
        $recipe->forceFill([
            'user_id' => $fixture['nativeActor']->getKey(), 'organization_id' => null,
            'name' => 'PRIVATE RECIPE TITLE 98f24', 'description' => null, 'script' => 'PRIVATE SCRIPT BODY 98f24',
            'is_published' => false, 'published_at' => null, 'gallery_revision_at' => null,
            'source_recipe_id' => null, 'source_revision_at' => null,
        ])->save();
        $configuration = [
            'schema_version' => 1,
            'project_name' => 'Checkout',
            'template' => array_key_first(config('application-templates')),
            'environment_recipes' => [['environment' => 'production', 'recipe_ids' => [(int) $recipe->getKey()]]],
        ];
        if ($secondEnvironment) {
            $configuration['environment_recipes'][] = ['environment' => 'staging', 'recipe_ids' => [(int) $recipe->getKey()]];
        }
        $provider = $this->provider();
        $preview = $provider->preview($fixture['target'], $configuration);
        $this->assertTrue($preview->ready(), implode('; ', $preview->blockers));
        $attempt = $this->acceptedAttempt($fixture, $configuration, $preview->authority);
        $result = $provider->apply($attempt);
        $nativeProject = NativeProject::query()->whereKey($result->resources[0]->sourceId)->sole();
        $nativeEnvironments = collect($result->resources)->filter(fn ($resource) => $resource->type === 'environment')
            ->mapWithKeys(fn ($resource): array => [$resource->environmentKey => Environment::query()->whereKey($resource->sourceId)->sole()]);
        $nativeEnvironment = $nativeEnvironments['production'];
        ProjectResource::query()->create([
            'project_id' => $fixture['project']->getKey(), 'environment_id' => null,
            'product' => 'deployer', 'resource_type' => 'project', 'resource_id' => (string) $nativeProject->getKey(),
            'name' => $nativeProject->name, 'status' => 'active',
        ]);
        foreach ($nativeEnvironments as $key => $native) {
            ProjectResource::query()->create([
                'project_id' => $fixture['project']->getKey(),
                'environment_id' => $fixture['target']->environments[$key]['id'],
                'product' => 'deployer', 'resource_type' => 'environment', 'resource_id' => (string) $native->getKey(),
                'name' => $native->name, 'status' => 'active',
            ]);
        }

        return [
            'fixture' => $fixture, 'nativeProject' => $nativeProject, 'nativeEnvironment' => $nativeEnvironment,
            'stagingEnvironment' => $nativeEnvironments->get('staging'),
            'recipe' => $recipe, 'provider' => $provider, 'configuration' => $configuration, 'attempt' => $attempt,
            'snapshot' => EnvironmentBlueprintRecipe::query()->where('step_id', $attempt->stepId)
                ->where('environment_key', 'production')->sole(),
        ];
    }

    /** @return array<string, mixed> */
    private function scenario(string $slug, bool $foreignWorkspace = false, bool $secondEnvironment = false): array
    {
        $nativeActor = User::factory()->create();
        $personalOrganization = $nativeActor->currentOrganization;
        $nativeWorkspaceOwner = null;
        $organization = $personalOrganization;
        if ($foreignWorkspace) {
            $nativeWorkspaceOwner = User::factory()->create();
            $organization = $nativeWorkspaceOwner->currentOrganization;
            $organization->members()->attach($nativeActor, ['role' => 'admin']);
            $nativeActor->forceFill(['current_organization_id' => $organization->getKey()])->save();
        }
        $email = $slug.'@example.test';
        $actor = PlatformUser::query()->forceCreate([
            'id' => (string) Str::ulid(), 'name' => 'Platform owner', 'email' => $email,
            'email_normalized' => $email, 'status' => 'active', 'email_verified_at' => now(),
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
        $targetEnvironments = [
            'production' => ['id' => (string) $environment->getKey(), 'name' => 'Production', 'type' => 'production'],
        ];
        if ($secondEnvironment) {
            $staging = ProjectEnvironment::query()->create([
                'project_id' => $project->getKey(), 'created_by_user_id' => $actor->getKey(), 'name' => 'Staging',
                'slug' => 'staging', 'environment_type' => 'staging', 'status' => 'active',
            ]);
            $targetEnvironments['staging'] = [
                'id' => (string) $staging->getKey(), 'name' => 'Staging', 'type' => 'staging',
            ];
        }
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
            'nativeActor' => $nativeActor, 'nativeWorkspaceOwner' => $nativeWorkspaceOwner,
            'organization' => $organization, 'personalOrganization' => $personalOrganization, 'environment' => $environment,
            'target' => new BlueprintTarget((string) $actor->getKey(), (string) $workspace->getKey(), (string) $project->getKey(), $targetEnvironments),
        ];
    }

    /** @param array<string, mixed> $fixture @return array{native: User, platform: PlatformUser} */
    private function managerFor(array $fixture, string $slug): array
    {
        $native = User::factory()->create();
        $fixture['organization']->members()->attach($native, ['role' => 'admin']);
        $native->forceFill([
            'current_organization_id' => $fixture['organization']->getKey(), 'email_verified_at' => now(),
        ])->save();
        $email = $slug.'@example.test';
        $platform = PlatformUser::query()->forceCreate([
            'id' => (string) Str::ulid(), 'name' => 'Workspace manager', 'email' => $email,
            'email_normalized' => $email, 'status' => 'active', 'email_verified_at' => now(),
        ]);
        LegacyIdentityMap::query()->create([
            'source_product' => 'deployer', 'source_entity' => 'user', 'source_id' => (string) $native->getKey(),
            'canonical_entity' => 'user', 'canonical_id' => (string) $platform->getKey(), 'status' => 'reconciled',
        ]);
        $membership = WorkspaceMembership::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(), 'user_id' => $platform->getKey(), 'role' => 'admin', 'status' => 'active',
        ]);
        WorkspaceProductAccess::query()->create([
            'membership_id' => $membership->getKey(), 'product' => 'deployer', 'role' => 'admin', 'status' => 'active',
        ]);
        ProjectMembership::query()->create([
            'project_id' => $fixture['project']->getKey(), 'user_id' => $platform->getKey(), 'role' => 'admin', 'status' => 'active',
        ]);

        return ['native' => $native, 'platform' => $platform];
    }

    /** @param array<string, mixed> $fixture @param array<string, mixed> $configuration */
    private function acceptedAttempt(array $fixture, array $configuration, array $nativeAuthority): BlueprintStepAttempt
    {
        $blueprint = ProjectBlueprint::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(), 'created_by_user_id' => $fixture['actor']->getKey(),
            'name' => 'Fixture', 'latest_version' => 1,
        ]);
        $version = ProjectBlueprintVersion::query()->create([
            'project_blueprint_id' => $blueprint->getKey(), 'created_by_user_id' => $fixture['actor']->getKey(), 'version' => 1,
            'definition' => [
                'environments' => collect($fixture['target']->environments)
                    ->map(fn (array $definition, string $key): array => [
                        'key' => $key, 'name' => $definition['name'], 'type' => $definition['type'],
                    ])->values()->all(),
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

    private function provider(): DeployerProjectBlueprintProvider
    {
        return new DeployerProjectBlueprintProvider(
            app(BlueprintAuthority::class), app(LegacyIdentityResolver::class), app(ApplicationTemplateCatalog::class),
            app(Entitlements::class), app(ApplyApplicationTemplate::class),
        );
    }

    /** Move accepted native authoring onto another user's workspace before testing account-scope cleanup. */
    private function prepareForeignWorkspaceForActorDeletion(array $prepared): void
    {
        $actor = $prepared['fixture']['nativeActor'];
        $workspaceOwner = $prepared['fixture']['nativeWorkspaceOwner'];
        $prepared['nativeProject']->forceFill(['created_by' => $workspaceOwner->getKey()])->save();
        $prepared['fixture']['organization']->members()->detach($actor);
        $actor->forceFill(['current_organization_id' => $prepared['fixture']['personalOrganization']->getKey()])->save();
        $prepared['recipe']->delete();
    }

    private function accountDeletionTarget(User $actor): ProductDeletionTarget
    {
        $ownedWorkspaceIds = DB::connection('deployer')->table('organizations')->where('owner_id', $actor->getKey())
            ->orderBy('id')->pluck('id')->map(fn ($id): string => (string) $id)->all();

        return new ProductDeletionTarget(
            'deployer', 'account', (string) $actor->getKey(), (string) $actor->getKey(), 'core-account', 'core-actor', $ownedWorkspaceIds,
        );
    }

    private function accountDeletionAttempt(ProductDeletionTarget $target, string $slug): ProductDeletionAttempt
    {
        return new ProductDeletionAttempt(
            'archive-delete-'.$slug, 'archive-prepare-'.$slug, $target, str_repeat('d', 64), 1, 'archive-test-lease', 'prepare',
        );
    }
}
