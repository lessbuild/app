<?php

namespace Tests\Feature\Deployer;

use App\Core\Exceptions\Blueprints\BlueprintBlocked;
use App\Core\Models\ProjectBlueprintStep;
use App\Modules\Deployer\Models\BlueprintApplicationReceipt;
use App\Modules\Deployer\Models\EnvironmentBlueprintRecipe;
use App\Modules\Deployer\Services\EnvironmentBlueprintRecipeTombstoneEvidence;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Focused coverage for opaque recipe archive evidence; intentionally not executed here. */
final class EnvironmentBlueprintRecipeTombstoneEvidenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
    }

    public function test_accepted_snapshot_creates_deterministic_signed_slot_evidence_without_a_lease(): void
    {
        [$step, $receipt, $snapshot] = $this->fixture();
        $service = new EnvironmentBlueprintRecipeTombstoneEvidence;
        $archivedAt = Carbon::parse('2026-09-25 12:34:56', 'UTC');

        $first = $service->makeTombstone($step, $receipt, $snapshot, $archivedAt);
        $second = $service->makeTombstone($step, $receipt, $snapshot, $archivedAt);

        $this->assertFalse($first->exists);
        $this->assertSame('01J8Y7Z9AABBCCDDEEFFGGHHAA', $first->step_id);
        $this->assertSame('702', (string) $first->environment_id);
        $this->assertSame(0, $first->position);
        $this->assertSame(1, $first->signature_version);
        $this->assertSame('app-key-v1', $first->signing_key_id);
        $this->assertSame($first->slot_commitment, $second->slot_commitment);
        $this->assertSame($first->tombstone_signature, $second->tombstone_signature);
        $this->assertSame(
            $first->slot_commitment,
            $service->commitmentForSlot($step, $receipt, 'production', 0),
        );
        $this->assertTrue($service->verifyTombstone($first, $step, $receipt));
    }

    public function test_tombstone_rejects_tampering_and_receipt_payload_mismatch(): void
    {
        [$step, $receipt, $snapshot] = $this->fixture();
        $service = new EnvironmentBlueprintRecipeTombstoneEvidence;
        $tombstone = $service->makeTombstone($step, $receipt, $snapshot, Carbon::parse('2026-09-25 12:34:56', 'UTC'));

        $tombstone->position = 1;
        $this->assertFalse($service->verifyTombstone($tombstone, $step, $receipt));

        $receipt->payload_hash = str_repeat('b', 64);
        $this->expectException(BlueprintBlocked::class);
        $service->makeTombstone($step, $receipt, $snapshot);
    }

    public function test_snapshot_actor_must_match_the_accepted_native_receipt_actor(): void
    {
        [$step, $receipt, $snapshot] = $this->fixture();
        $snapshot->actor_source_id = 503;
        $snapshot->binding_fingerprint = $this->bindingFingerprint($snapshot);

        $this->expectException(BlueprintBlocked::class);
        (new EnvironmentBlueprintRecipeTombstoneEvidence)->makeTombstone($step, $receipt, $snapshot);
    }

    public function test_metadata_binding_verification_does_not_decrypt_the_snapshot_script(): void
    {
        [, , $snapshot] = $this->fixture();
        $service = new EnvironmentBlueprintRecipeTombstoneEvidence;

        $this->assertTrue($service->verifySnapshotBinding($snapshot));

        $snapshot->source_name = 'tampered metadata';
        $this->assertFalse($service->verifySnapshotBinding($snapshot));
    }

    public function test_missing_or_rotated_current_app_key_fails_closed(): void
    {
        [$step, $receipt, $snapshot] = $this->fixture();
        $service = new EnvironmentBlueprintRecipeTombstoneEvidence;
        $tombstone = $service->makeTombstone($step, $receipt, $snapshot, Carbon::parse('2026-09-25 12:34:56', 'UTC'));

        config(['app.key' => 'rotated-literal-app-key']);
        $this->assertFalse($service->verifyTombstone($tombstone, $step, $receipt));
        $this->assertFalse($service->verifySnapshotBinding($snapshot));

        config(['app.key' => '']);
        $this->assertFalse($service->verifyTombstone($tombstone, $step, $receipt));
        $this->assertFalse($service->verifySnapshotBinding($snapshot));
    }

    /** @return array{ProjectBlueprintStep, BlueprintApplicationReceipt, EnvironmentBlueprintRecipe} */
    private function fixture(): array
    {
        $stepId = '01J8Y7Z9AABBCCDDEEFFGGHHAA';
        $target = [
            'actorId' => 'canonical-user',
            'workspaceId' => 'canonical-workspace',
            'projectId' => 'canonical-project',
            'environments' => [
                'production' => ['id' => 'canonical-environment', 'name' => 'Production', 'type' => 'production'],
            ],
        ];
        $payloadHash = str_repeat('a', 64);
        $step = new ProjectBlueprintStep;
        $step->forceFill([
            'id' => $stepId,
            'product' => 'deployer',
            'target' => $target,
            'configuration' => [
                'schema_version' => 1,
                'project_name' => 'Accepted project',
                'template' => 'laravel',
                'environment_recipes' => [['environment' => 'production', 'recipe_ids' => [1234]]],
            ],
            'payload_hash' => $payloadHash,
            'status' => 'pending',
            'lease_token' => null,
            'completed_at' => null,
        ]);

        $receipt = new BlueprintApplicationReceipt;
        $receipt->forceFill([
            'step_id' => $stepId,
            'workspace_source_id' => '501',
            'actor_source_id' => '502',
            'project_source_id' => '701',
            'canonical_project_id' => 'canonical-project',
            'payload_hash' => $payloadHash,
            'completed_at' => Carbon::parse('2026-09-25 12:00:00', 'UTC'),
            'result' => [
                'resources' => [
                    ['type' => 'project', 'sourceId' => '701', 'name' => 'Accepted project', 'environmentKey' => null, 'parentSourceId' => null],
                    ['type' => 'environment', 'sourceId' => '702', 'name' => 'Production', 'environmentKey' => 'production', 'parentSourceId' => '701'],
                ],
                'requirements' => [],
            ],
        ]);

        $snapshot = new EnvironmentBlueprintRecipe;
        $snapshot->forceFill([
            'step_id' => $stepId,
            'actor_source_id' => 502,
            'workspace_source_id' => 501,
            'canonical_project_id' => 'canonical-project',
            'canonical_environment_id' => 'canonical-environment',
            'environment_key' => 'production',
            'environment_id' => 702,
            'source_recipe_id' => 1234,
            'source_user_id' => null,
            'source_organization_id' => 501,
            'source_gallery_recipe_id' => null,
            'source_name' => 'Prepared recipe',
            'source_description' => null,
            'source_is_published' => false,
            'source_updated_at' => null,
            'source_revision_at' => null,
            'source_published_at' => null,
            'source_gallery_revision_at' => null,
            'script_snapshot' => 'signed but private snapshot text',
            'script_fingerprint' => str_repeat('c', 64),
            'position' => 0,
        ]);
        $snapshot->setAttribute('binding_fingerprint', $this->bindingFingerprint($snapshot));
        $snapshotAttributes = $snapshot->getAttributes();
        $snapshotAttributes['script_snapshot'] = 'deliberately-corrupt-encrypted-payload';
        $snapshot->setRawAttributes($snapshotAttributes, true);

        return [$step, $receipt, $snapshot];
    }

    private function bindingFingerprint(EnvironmentBlueprintRecipe $snapshot): string
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
            'source_updated_at' => null,
            'source_revision_at' => null,
            'source_published_at' => null,
            'source_gallery_revision_at' => null,
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

        return hash_hmac(
            'sha256',
            json_encode($attributes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            trim((string) config('app.key')),
        );
    }
}
