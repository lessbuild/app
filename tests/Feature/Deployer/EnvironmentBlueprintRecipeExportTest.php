<?php

namespace Tests\Feature\Deployer;

use App\Modules\Deployer\Models\EnvironmentBlueprintRecipe;
use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\Recipe;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\ExportOrganizationData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Focused coverage for safe export and deletion behavior of prepared recipe snapshots. */
final class EnvironmentBlueprintRecipeExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.products.deployer.auth_authority' => 'legacy']);
    }

    public function test_export_contains_safe_snapshot_metadata_but_omits_unshared_personal_metadata_and_all_script_material(): void
    {
        $owner = User::factory()->create();
        $organization = $owner->currentOrganization;
        $project = $organization->projects()->create([
            'created_by' => $owner->getKey(), 'name' => 'Prepared project', 'slug' => 'prepared-export',
        ]);
        $environment = $project->environments()->create([
            'name' => 'Production', 'slug' => 'production', 'type' => 'production',
        ]);
        $workspaceScript = 'echo workspace-private-script-marker';
        $personalScript = 'echo personal-private-script-marker';
        $workspaceSnapshot = $this->snapshot($environment->getKey(), [
            'step_id' => '01J8Y7Z9AABBCCDDEEFFGGHHII',
            'source_recipe_id' => 7001,
            'source_organization_id' => $organization->getKey(),
            'source_name' => 'Workspace prepared recipe',
            'source_description' => 'Safe workspace source metadata',
            'script_snapshot' => $workspaceScript,
            'script_fingerprint' => str_repeat('a', 64),
            'binding_fingerprint' => str_repeat('b', 64),
        ]);
        $personalSnapshot = $this->snapshot($environment->getKey(), [
            'step_id' => '01J8Y7Z9AABBCCDDEEFFGGHHIJ',
            'source_recipe_id' => 7002,
            'source_user_id' => $owner->getKey(),
            'source_organization_id' => null,
            'source_name' => 'Personal-only recipe label',
            'source_description' => 'This is not shared yet',
            'script_snapshot' => $personalScript,
            'script_fingerprint' => str_repeat('c', 64),
            'binding_fingerprint' => str_repeat('d', 64),
        ]);
        $personalSnapshot->forceFill([
            'binding_fingerprint' => $this->snapshotBindingFingerprint($personalSnapshot),
        ])->save();

        $unsharedExport = $this->export($organization);
        $unsharedRecord = collect($this->records($unsharedExport))->firstWhere('type', 'environment_blueprint_recipe_metadata');
        $this->assertSame($workspaceSnapshot->getKey(), $unsharedRecord['data']['id']);
        $this->assertSame($environment->getKey(), $unsharedRecord['data']['environment_id']);
        $this->assertSame('Workspace prepared recipe', $unsharedRecord['data']['source_name']);
        $this->assertArrayNotHasKey('actor_source_id', $unsharedRecord['data']);
        $this->assertArrayNotHasKey('source_user_id', $unsharedRecord['data']);
        $this->assertStringNotContainsString('Personal-only recipe label', $unsharedExport);
        $this->assertStringNotContainsString($workspaceScript, $unsharedExport);
        $this->assertStringNotContainsString($personalScript, $unsharedExport);

        $workspaceCopy = new Recipe;
        $workspaceCopy->forceFill([
            'user_id' => $owner->getKey(), 'organization_id' => $organization->getKey(),
            'name' => 'Shared prepared copy', 'description' => null, 'script' => $personalScript,
            'is_published' => false, 'source_recipe_id' => null, 'source_revision_at' => null,
        ])->save();
        $installReceipt = hash_hmac('sha256', json_encode([
            'purpose' => 'blueprint-recipe-snapshot-install',
            'binding_fingerprint' => (string) $personalSnapshot->binding_fingerprint,
            'workspace_id' => (string) $organization->getKey(),
            'recipe_id' => (string) $workspaceCopy->getKey(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), trim((string) config('app.key')));
        $personalSnapshot->forceFill([
            'installed_recipe_id' => $workspaceCopy->getKey(), 'install_attempted_at' => now(),
            'install_receipt_fingerprint' => $installReceipt,
        ])->save();
        $ciphertexts = DB::connection('deployer')->table('environment_blueprint_recipes')
            ->whereIn('id', [$workspaceSnapshot->getKey(), $personalSnapshot->getKey()])->pluck('script_snapshot')->all();
        $sharedExport = $this->export($organization);
        $sharedRecords = collect($this->records($sharedExport))->where('type', 'environment_blueprint_recipe_metadata');

        $this->assertCount(2, $sharedRecords);
        $this->assertTrue($sharedRecords->contains(fn (array $record): bool => $record['data']['id'] === $personalSnapshot->getKey()));
        foreach ([
            ...$ciphertexts,
            str_repeat('a', 64),
            str_repeat('b', 64),
            str_repeat('c', 64),
            (string) $personalSnapshot->binding_fingerprint,
        ] as $secretMaterial) {
            $this->assertStringNotContainsString($secretMaterial, $sharedExport);
        }
        $this->assertStringNotContainsString($workspaceScript, $sharedExport);
        $this->assertStringNotContainsString($personalScript, $sharedExport);

        DB::connection('deployer')->table('environment_blueprint_recipes')
            ->where('id', $personalSnapshot->getKey())->update(['install_receipt_fingerprint' => str_repeat('0', 64)]);
        $tamperedExport = $this->export($organization);
        $tamperedRecords = collect($this->records($tamperedExport))->where('type', 'environment_blueprint_recipe_metadata');
        $this->assertCount(1, $tamperedRecords);
        $this->assertStringNotContainsString('Personal-only recipe label', $tamperedExport);
    }

    public function test_export_rejects_a_valid_install_receipt_tuple_transplanted_to_another_personal_snapshot(): void
    {
        $owner = User::factory()->create();
        $organization = $owner->currentOrganization;
        $project = $organization->projects()->create([
            'created_by' => $owner->getKey(), 'name' => 'Transplant project', 'slug' => 'transplant-export',
        ]);
        $environment = $project->environments()->create([
            'name' => 'Production', 'slug' => 'production', 'type' => 'production',
        ]);
        $sharedSnapshot = $this->snapshot($environment->getKey(), [
            'step_id' => '01J8Y7Z9AABBCCDDEEFFGGHHIL',
            'source_user_id' => $owner->getKey(), 'source_organization_id' => null,
            'source_name' => 'Installed personal recipe', 'source_description' => 'Legitimately shared metadata',
            'script_fingerprint' => str_repeat('a', 64),
        ]);
        $sharedSnapshot->forceFill([
            'binding_fingerprint' => $this->snapshotBindingFingerprint($sharedSnapshot),
        ])->save();
        $unrelatedSnapshot = $this->snapshot($environment->getKey(), [
            'step_id' => '01J8Y7Z9AABBCCDDEEFFGGHHIM',
            'source_user_id' => $owner->getKey(), 'source_organization_id' => null,
            'source_name' => 'Unrelated private recipe label', 'source_description' => 'Must stay private',
            'script_fingerprint' => str_repeat('b', 64),
        ]);
        $unrelatedSnapshot->forceFill([
            'binding_fingerprint' => $this->snapshotBindingFingerprint($unrelatedSnapshot),
        ])->save();

        $installedRecipe = new Recipe;
        $installedRecipe->forceFill([
            'user_id' => $owner->getKey(), 'organization_id' => $organization->getKey(),
            'name' => 'Installed personal copy', 'description' => null, 'script' => 'echo private',
            'is_published' => false, 'source_recipe_id' => null, 'source_revision_at' => null,
        ])->save();
        $installAttemptedAt = now();
        $receipt = hash_hmac('sha256', json_encode([
            'purpose' => 'blueprint-recipe-snapshot-install',
            'binding_fingerprint' => (string) $sharedSnapshot->binding_fingerprint,
            'workspace_id' => (string) $organization->getKey(),
            'recipe_id' => (string) $installedRecipe->getKey(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), trim((string) config('app.key')));
        $transplantedTuple = [
            'binding_fingerprint' => $sharedSnapshot->binding_fingerprint,
            'installed_recipe_id' => $installedRecipe->getKey(),
            'install_attempted_at' => $installAttemptedAt,
            'install_receipt_fingerprint' => $receipt,
        ];
        DB::connection('deployer')->table('environment_blueprint_recipes')
            ->where('id', $sharedSnapshot->getKey())->update($transplantedTuple);
        DB::connection('deployer')->table('environment_blueprint_recipes')
            ->where('id', $unrelatedSnapshot->getKey())->update($transplantedTuple);

        $export = $this->export($organization);
        $records = collect($this->records($export))->where('type', 'environment_blueprint_recipe_metadata');

        $this->assertCount(1, $records);
        $this->assertSame($sharedSnapshot->getKey(), $records->first()['data']['id']);
        $this->assertStringNotContainsString('Unrelated private recipe label', $export);
    }

    public function test_source_recipe_deletion_preserves_snapshot_and_workspace_deletion_cascades_it(): void
    {
        $owner = User::factory()->create();
        $organization = $owner->currentOrganization;
        $project = $organization->projects()->create([
            'created_by' => $owner->getKey(), 'name' => 'Retention project', 'slug' => 'retention-export',
        ]);
        $environment = $project->environments()->create([
            'name' => 'Production', 'slug' => 'production', 'type' => 'production',
        ]);
        $source = new Recipe;
        $source->forceFill([
            'user_id' => $owner->getKey(), 'organization_id' => $organization->getKey(),
            'name' => 'Historical source', 'description' => null, 'script' => 'echo immutable', 'is_published' => false,
        ])->save();
        $snapshot = $this->snapshot($environment->getKey(), [
            'step_id' => '01J8Y7Z9AABBCCDDEEFFGGHHIK',
            'source_recipe_id' => $source->getKey(), 'source_organization_id' => $organization->getKey(),
            'source_name' => $source->name, 'script_snapshot' => 'echo immutable',
            'script_fingerprint' => str_repeat('e', 64), 'binding_fingerprint' => str_repeat('f', 64),
        ]);

        $source->delete();
        $this->assertDatabaseHas('environment_blueprint_recipes', ['id' => $snapshot->getKey()], 'deployer');

        $organization->delete();
        $this->assertDatabaseCount('environment_blueprint_recipes', 0, 'deployer');
    }

    public function test_export_remains_available_before_snapshot_store_migration(): void
    {
        $owner = User::factory()->create();
        Schema::connection('deployer')->dropIfExists('environment_blueprint_recipes');

        $export = $this->export($owner->currentOrganization);
        $records = collect($this->records($export));

        $this->assertTrue($records->contains(fn (array $record): bool => $record['type'] === 'workspace'));
        $this->assertFalse($records->contains(fn (array $record): bool => $record['type'] === 'environment_blueprint_recipe_metadata'));
    }

    /** @param array<string, mixed> $attributes */
    private function snapshot(int|string $environmentId, array $attributes): EnvironmentBlueprintRecipe
    {
        return EnvironmentBlueprintRecipe::query()->create(array_merge([
            'step_id' => '01J8Y7Z9AABBCCDDEEFFGGHHA',
            'actor_source_id' => 999,
            'workspace_source_id' => 888,
            'canonical_project_id' => 'core-project',
            'canonical_environment_id' => 'core-environment',
            'environment_key' => 'production',
            'environment_id' => $environmentId,
            'source_recipe_id' => 123,
            'source_user_id' => null,
            'source_organization_id' => 888,
            'source_gallery_recipe_id' => null,
            'source_name' => 'Prepared source',
            'source_description' => null,
            'source_is_published' => false,
            'position' => 0,
            'script_snapshot' => 'echo prepared',
            'script_fingerprint' => str_repeat('1', 64),
            'binding_fingerprint' => str_repeat('2', 64),
        ], $attributes));
    }

    private function snapshotBindingFingerprint(EnvironmentBlueprintRecipe $snapshot): string
    {
        $attributes = [
            'step_id' => $snapshot->step_id,
            'actor_source_id' => $snapshot->actor_source_id,
            'workspace_source_id' => $snapshot->workspace_source_id,
            'canonical_project_id' => $snapshot->canonical_project_id,
            'canonical_environment_id' => $snapshot->canonical_environment_id,
            'environment_key' => $snapshot->environment_key,
            'environment_id' => $snapshot->environment_id,
            'source_recipe_id' => $snapshot->source_recipe_id,
            'source_user_id' => $snapshot->source_user_id,
            'source_organization_id' => $snapshot->source_organization_id,
            'source_gallery_recipe_id' => $snapshot->source_gallery_recipe_id,
            'source_name' => $snapshot->source_name,
            'source_description' => $snapshot->source_description,
            'source_is_published' => (bool) $snapshot->source_is_published,
            'source_updated_at' => $this->snapshotTimestamp($snapshot->source_updated_at),
            'source_revision_at' => $this->snapshotTimestamp($snapshot->source_revision_at),
            'source_published_at' => $this->snapshotTimestamp($snapshot->source_published_at),
            'source_gallery_revision_at' => $this->snapshotTimestamp($snapshot->source_gallery_revision_at),
            'script_fingerprint' => (string) $snapshot->script_fingerprint,
            'position' => (int) $snapshot->position,
        ];
        foreach ([
            'actor_source_id', 'workspace_source_id', 'environment_id', 'source_recipe_id', 'source_user_id',
            'source_organization_id', 'source_gallery_recipe_id',
        ] as $field) {
            $attributes[$field] = $attributes[$field] === null ? null : (string) $attributes[$field];
        }
        foreach ([
            'step_id', 'canonical_project_id', 'canonical_environment_id', 'environment_key', 'source_name',
            'source_description', 'script_fingerprint',
        ] as $field) {
            $attributes[$field] = $attributes[$field] === null ? null : (string) $attributes[$field];
        }

        return hash_hmac('sha256', json_encode(
            $attributes,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ), trim((string) config('app.key')));
    }

    private function snapshotTimestamp(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : (string) $value;
    }

    private function export(Organization $organization): string
    {
        $stream = fopen('php://temp', 'w+');
        app(ExportOrganizationData::class)->write($organization, $stream);
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return $content;
    }

    /** @return list<array{type: string, data: array<string, mixed>}> */
    private function records(string $content): array
    {
        return collect(explode("\n", trim($content)))
            ->map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR))
            ->all();
    }
}
