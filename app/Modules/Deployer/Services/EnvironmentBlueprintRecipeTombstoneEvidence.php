<?php

namespace App\Modules\Deployer\Services;

use App\Core\Exceptions\Blueprints\BlueprintBlocked;
use App\Core\Models\ProjectBlueprintStep;
use App\Modules\Deployer\Models\BlueprintApplicationReceipt;
use App\Modules\Deployer\Models\EnvironmentBlueprintRecipe;
use App\Modules\Deployer\Models\EnvironmentBlueprintRecipeTombstone;
use DateTimeInterface;
use Throwable;

/**
 * Creates and checks privacy-preserving evidence for accepted Deployer recipe slots.
 *
 * Version 1 signs with the current trimmed literal APP_KEY. There is deliberately no key
 * ring: changing APP_KEY requires a separate rotation gate before existing evidence can
 * continue to verify.
 */
final class EnvironmentBlueprintRecipeTombstoneEvidence
{
    private const SIGNATURE_VERSION = 1;

    private const SIGNING_KEY_ID = 'app-key-v1';

    private const SLOT_DOMAIN = 'deployer.environment-blueprint-recipe-slot.v1';

    private const TOMBSTONE_DOMAIN = 'deployer.environment-blueprint-recipe-tombstone.v1';

    /**
     * Build an unsaved tombstone for a snapshot that matches an immutable accepted step and receipt.
     * The snapshot binding check intentionally reads metadata only, never its encrypted script.
     */
    public function makeTombstone(
        ProjectBlueprintStep $step,
        BlueprintApplicationReceipt $receipt,
        EnvironmentBlueprintRecipe $snapshot,
        ?DateTimeInterface $archivedAt = null,
    ): EnvironmentBlueprintRecipeTombstone {
        if (! $this->verifySnapshotBinding($snapshot)) {
            throw new BlueprintBlocked('resource_conflict');
        }

        $slot = $this->acceptedSlot($step, $receipt, (string) $snapshot->environment_key, (int) $snapshot->position);
        $this->assertSnapshotMatchesSlot($snapshot, $slot);
        $key = $this->signingKey();
        $archivedAtValue = $archivedAt?->format('Y-m-d H:i:s') ?? now()->format('Y-m-d H:i:s');

        $tombstone = new EnvironmentBlueprintRecipeTombstone([
            'step_id' => $slot['step_id'],
            'environment_id' => $slot['native_environment_id'],
            'position' => $slot['position'],
            'archived_at' => $archivedAtValue,
            'signature_version' => self::SIGNATURE_VERSION,
            'signing_key_id' => self::SIGNING_KEY_ID,
            'slot_commitment' => $this->slotCommitment($slot, $key),
        ]);
        $tombstone->forceFill([
            'tombstone_signature' => $this->tombstoneSignature($tombstone, $key),
        ]);

        return $tombstone;
    }

    /**
     * Derive the same opaque commitment from accepted immutable Core and Deployer evidence.
     * This is useful to check uniqueness before persisting a tombstone.
     */
    public function commitmentForSlot(
        ProjectBlueprintStep $step,
        BlueprintApplicationReceipt $receipt,
        string $environmentKey,
        int $position,
    ): string {
        $slot = $this->acceptedSlot($step, $receipt, $environmentKey, $position);

        return $this->slotCommitment($slot, $this->signingKey());
    }

    /**
     * Verify a stored tombstone against the accepted step and receipt without needing a lease.
     */
    public function verifyTombstone(
        EnvironmentBlueprintRecipeTombstone $tombstone,
        ProjectBlueprintStep $step,
        BlueprintApplicationReceipt $receipt,
    ): bool {
        try {
            if ((int) $tombstone->signature_version !== self::SIGNATURE_VERSION
                || (string) $tombstone->signing_key_id !== self::SIGNING_KEY_ID
                || ! $this->isHexDigest((string) $tombstone->slot_commitment)
                || ! $this->isHexDigest((string) $tombstone->tombstone_signature)
                || $tombstone->archived_at === null) {
                return false;
            }

            $key = $this->signingKey();
            $candidates = $this->acceptedSlots($step, $receipt);
            $matches = array_values(array_filter($candidates, fn (array $slot): bool => $slot['native_environment_id'] === (string) $tombstone->environment_id
                && $slot['position'] === (int) $tombstone->position
                && $slot['step_id'] === (string) $tombstone->step_id));
            if (count($matches) !== 1) {
                return false;
            }

            $expectedCommitment = $this->slotCommitment($matches[0], $key);
            if (! hash_equals($expectedCommitment, (string) $tombstone->slot_commitment)) {
                return false;
            }

            return hash_equals($this->tombstoneSignature($tombstone, $key), (string) $tombstone->tombstone_signature);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Verify the existing snapshot binding HMAC using metadata only.
     * In particular, do not read $snapshot->script_snapshot here.
     */
    public function verifySnapshotBinding(EnvironmentBlueprintRecipe $snapshot): bool
    {
        try {
            $key = $this->signingKey();
            $attributes = $this->snapshotMetadata($snapshot);
            if (! $this->isHexDigest($attributes['script_fingerprint'])
                || ! $this->isHexDigest((string) $snapshot->binding_fingerprint)) {
                return false;
            }

            $expected = hash_hmac('sha256', $this->json($attributes), $key);

            return hash_equals($expected, (string) $snapshot->binding_fingerprint);
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    private function snapshotMetadata(EnvironmentBlueprintRecipe $snapshot): array
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
            'source_updated_at' => $this->timestamp($snapshot->source_updated_at),
            'source_revision_at' => $this->timestamp($snapshot->source_revision_at),
            'source_published_at' => $this->timestamp($snapshot->source_published_at),
            'source_gallery_revision_at' => $this->timestamp($snapshot->source_gallery_revision_at),
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

        return $attributes;
    }

    /** @return list<array<string, mixed>> */
    private function acceptedSlots(ProjectBlueprintStep $step, BlueprintApplicationReceipt $receipt): array
    {
        $this->assertAcceptedEnvelope($step, $receipt);
        $configuration = $step->configuration;
        $environmentRecipes = $configuration['environment_recipes'] ?? [];
        if (! is_array($environmentRecipes) || ! array_is_list($environmentRecipes)) {
            throw new BlueprintBlocked('resource_conflict');
        }

        $slots = [];
        foreach ($environmentRecipes as $entry) {
            if (! is_array($entry) || ! is_string($entry['environment'] ?? null)
                || ! is_array($entry['recipe_ids'] ?? null) || ! array_is_list($entry['recipe_ids'])) {
                throw new BlueprintBlocked('resource_conflict');
            }
            foreach (array_keys($entry['recipe_ids']) as $position) {
                $slots[] = $this->acceptedSlot($step, $receipt, $entry['environment'], (int) $position);
            }
        }

        return $slots;
    }

    /** @return array<string, mixed> */
    private function acceptedSlot(
        ProjectBlueprintStep $step,
        BlueprintApplicationReceipt $receipt,
        string $environmentKey,
        int $position,
    ): array {
        $this->assertAcceptedEnvelope($step, $receipt);
        if ($environmentKey === '' || $position < 0 || $position > 65535) {
            throw new BlueprintBlocked('resource_conflict');
        }

        $target = $step->target;
        $configuration = $step->configuration;
        $targetEnvironments = $target['environments'] ?? null;
        if (! is_array($targetEnvironments) || ! isset($targetEnvironments[$environmentKey])
            || ! is_array($targetEnvironments[$environmentKey])) {
            throw new BlueprintBlocked('resource_conflict');
        }
        $targetEnvironment = $targetEnvironments[$environmentKey];
        if (! is_string($targetEnvironment['name'] ?? null) || trim($targetEnvironment['name']) === ''
            || ! is_string($targetEnvironment['type'] ?? null) || trim($targetEnvironment['type']) === '') {
            throw new BlueprintBlocked('resource_conflict');
        }
        $canonicalEnvironmentId = $this->requiredId($targetEnvironment['id'] ?? null);

        $recipeEntries = $configuration['environment_recipes'] ?? [];
        if (! is_array($recipeEntries) || ! array_is_list($recipeEntries)) {
            throw new BlueprintBlocked('resource_conflict');
        }
        $matchingEntries = array_values(array_filter($recipeEntries, fn (mixed $entry): bool => is_array($entry) && ($entry['environment'] ?? null) === $environmentKey));
        if (count($matchingEntries) !== 1 || ! is_array($matchingEntries[0]['recipe_ids'] ?? null)
            || ! array_is_list($matchingEntries[0]['recipe_ids']) || ! array_key_exists($position, $matchingEntries[0]['recipe_ids'])) {
            throw new BlueprintBlocked('resource_conflict');
        }
        $sourceRecipeIdValue = $matchingEntries[0]['recipe_ids'][$position];
        if (! is_int($sourceRecipeIdValue) || $sourceRecipeIdValue < 1) {
            throw new BlueprintBlocked('resource_conflict');
        }
        $sourceRecipeId = (string) $sourceRecipeIdValue;

        $resources = $receipt->result['resources'] ?? null;
        if (! is_array($resources) || ! array_is_list($resources)) {
            throw new BlueprintBlocked('resource_conflict');
        }
        $environmentResources = array_values(array_filter($resources, fn (mixed $resource): bool => is_array($resource) && ($resource['type'] ?? null) === 'environment'
            && ($resource['environmentKey'] ?? null) === $environmentKey));
        if (count($environmentResources) !== 1) {
            throw new BlueprintBlocked('resource_conflict');
        }
        $environmentResource = $environmentResources[0];
        $nativeEnvironmentId = $this->requiredId($environmentResource['sourceId'] ?? null);
        if ($this->requiredId($environmentResource['parentSourceId'] ?? null) !== (string) $receipt->project_source_id) {
            throw new BlueprintBlocked('resource_conflict');
        }

        return [
            'payload_hash' => (string) $step->payload_hash,
            'step_id' => (string) $step->getKey(),
            'canonical_workspace_id' => (string) $target['workspaceId'],
            'workspace_source_id' => (string) $receipt->workspace_source_id,
            'actor_source_id' => (string) $receipt->actor_source_id,
            'canonical_project_id' => (string) $target['projectId'],
            'project_source_id' => (string) $receipt->project_source_id,
            'environment_key' => $environmentKey,
            'canonical_environment_id' => $canonicalEnvironmentId,
            'native_environment_id' => $nativeEnvironmentId,
            'position' => $position,
            'source_recipe_id' => $sourceRecipeId,
        ];
    }

    private function assertAcceptedEnvelope(ProjectBlueprintStep $step, BlueprintApplicationReceipt $receipt): void
    {
        $target = $step->target;
        if ($step->product !== 'deployer' || ! is_array($target)
            || $this->requiredId($step->getKey()) === ''
            || ! preg_match('/\A[a-f0-9]{64}\z/', (string) $step->payload_hash)
            || (string) $receipt->step_id !== (string) $step->getKey()
            || ! hash_equals((string) $step->payload_hash, (string) $receipt->payload_hash)
            || $receipt->completed_at === null
            || (string) $receipt->canonical_project_id !== (string) ($target['projectId'] ?? '')
            || $this->requiredId($target['actorId'] ?? null) === ''
            || $this->requiredId($target['workspaceId'] ?? null) === ''
            || $this->requiredId($target['projectId'] ?? null) === ''
            || $this->requiredId($receipt->actor_source_id) === ''
            || $this->requiredId($receipt->workspace_source_id) === ''
            || $this->requiredId($receipt->project_source_id) === '') {
            throw new BlueprintBlocked('resource_conflict');
        }

        $resources = $receipt->result['resources'] ?? null;
        if (! is_array($resources) || ! array_is_list($resources)) {
            throw new BlueprintBlocked('resource_conflict');
        }
        $projectResources = array_values(array_filter($resources, fn (mixed $resource): bool => is_array($resource) && ($resource['type'] ?? null) === 'project'));
        if (count($projectResources) !== 1
            || $this->requiredId($projectResources[0]['sourceId'] ?? null) !== (string) $receipt->project_source_id) {
            throw new BlueprintBlocked('resource_conflict');
        }
    }

    /** @param array<string, mixed> $slot */
    private function assertSnapshotMatchesSlot(EnvironmentBlueprintRecipe $snapshot, array $slot): void
    {
        if ((string) $snapshot->step_id !== $slot['step_id']
            || (string) $snapshot->actor_source_id !== $slot['actor_source_id']
            || (string) $snapshot->workspace_source_id !== $slot['workspace_source_id']
            || (string) $snapshot->canonical_project_id !== $slot['canonical_project_id']
            || (string) $snapshot->canonical_environment_id !== $slot['canonical_environment_id']
            || (string) $snapshot->environment_key !== $slot['environment_key']
            || (string) $snapshot->environment_id !== $slot['native_environment_id']
            || (string) $snapshot->source_recipe_id !== $slot['source_recipe_id']
            || (int) $snapshot->position !== $slot['position']) {
            throw new BlueprintBlocked('resource_conflict');
        }
    }

    /** @param array<string, mixed> $slot */
    private function slotCommitment(array $slot, string $key): string
    {
        $slot['domain'] = self::SLOT_DOMAIN;
        $slot['signature_version'] = self::SIGNATURE_VERSION;
        $slot['signing_key_id'] = self::SIGNING_KEY_ID;

        return hash_hmac('sha256', $this->json($slot), $key);
    }

    private function tombstoneSignature(EnvironmentBlueprintRecipeTombstone $tombstone, string $key): string
    {
        return hash_hmac('sha256', $this->json([
            'domain' => self::TOMBSTONE_DOMAIN,
            'signature_version' => (int) $tombstone->signature_version,
            'signing_key_id' => (string) $tombstone->signing_key_id,
            'step_id' => (string) $tombstone->step_id,
            'environment_id' => (string) $tombstone->environment_id,
            'position' => (int) $tombstone->position,
            'archived_at' => $this->timestamp($tombstone->archived_at),
            'slot_commitment' => (string) $tombstone->slot_commitment,
        ]), $key);
    }

    private function signingKey(): string
    {
        $key = trim((string) config('app.key'));
        if ($key === '') {
            throw new BlueprintBlocked('native_state_changed');
        }

        return $key;
    }

    private function requiredId(mixed $value): string
    {
        if (! is_string($value) && ! is_int($value)) {
            throw new BlueprintBlocked('resource_conflict');
        }
        $id = (string) $value;
        if ($id === '') {
            throw new BlueprintBlocked('resource_conflict');
        }

        return $id;
    }

    private function isHexDigest(string $value): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/', $value) === 1;
    }

    private function timestamp(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s') : (string) $value;
    }

    /** @param array<string, mixed> $value */
    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
