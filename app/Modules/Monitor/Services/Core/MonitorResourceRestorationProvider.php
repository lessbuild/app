<?php

namespace App\Modules\Monitor\Services\Core;

use App\Core\Contracts\ProductResourceRestorationProvider;
use App\Core\Data\Restoration\NativeRestorationReceipt;
use App\Core\Data\Restoration\NativeRestorationSnapshot;
use App\Core\Data\Restoration\NativeRestorationState;
use App\Core\Data\Restoration\ResourceRestorationAttempt;
use App\Core\Data\Restoration\ResourceRestorationTarget;
use App\Core\Enums\ProjectResourceAccessPurpose;
use App\Core\Exceptions\Restoration\ResourceRestorationBlocked;
use App\Core\Exceptions\Restoration\ResourceRestorationSuperseded;
use App\Core\Models\PlatformUser;
use App\Core\Services\LegacyIdentityResolver;
use App\Core\Services\Restoration\ResourceRestorationAuthority;
use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\ResourceRestorationReceipt;
use App\Modules\Monitor\Models\Workspace;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class MonitorResourceRestorationProvider implements ProductResourceRestorationProvider
{
    public function __construct(private readonly MonitorProjectAccess $access, private readonly LegacyIdentityResolver $identities) {}

    public function product(): string
    {
        return 'monitor';
    }

    public function resourceTypes(): array
    {
        return ['application', 'environment'];
    }

    public function inspect(PlatformUser $actor, ResourceRestorationTarget $target, ?string $receiptRequestId = null): NativeRestorationSnapshot
    {
        return DB::connection('monitor')->transaction(function () use ($actor, $target, $receiptRequestId): NativeRestorationSnapshot {
            [$application, $environments] = $this->lockSource($target);
            $this->authorize($actor, $target, $application, $environments);

            $currentReceipt = null;
            if ($receiptRequestId !== null) {
                $stored = ResourceRestorationReceipt::query()->where('request_id', $receiptRequestId)->lockForUpdate()->first();
                if ($stored !== null && (string) $stored->application_id === (string) $application->getKey()
                    && $stored->resource_type === $target->resourceType && $stored->resource_id === $target->resourceId
                    && $stored->revision === (int) $application->lifecycle_revision) {
                    $candidate = new NativeRestorationReceipt($receiptRequestId, $stored->revision, $this->states($target, $application, $environments));
                    if (hash_equals($stored->receipt_hash, $candidate->hash()) && $stored->states === $candidate->toArray()['states']) {
                        $currentReceipt = $candidate;
                    }
                }
            }

            return new NativeRestorationSnapshot((int) $application->lifecycle_revision, $this->states($target, $application, $environments, restoring: true), $currentReceipt,
                $target->resourceType === 'environment' ? (string) $application->getKey() : null);
        });
    }

    public function apply(ResourceRestorationAttempt $attempt): NativeRestorationReceipt
    {
        return DB::connection('monitor')->transaction(function () use ($attempt): NativeRestorationReceipt {
            [$application, $environments] = $this->lockSource($attempt->target);
            $this->authorizeAttempt($attempt, $application, $environments);
            $existing = ResourceRestorationReceipt::query()->where('request_id', $attempt->requestId)->lockForUpdate()->first();
            if ($existing !== null) {
                return $this->verifyReceipt($attempt, $existing, $application, $environments);
            }
            if ((int) $application->lifecycle_revision !== $attempt->expectedRevision) {
                throw new ResourceRestorationSuperseded;
            }

            $resource = $attempt->target->resourceType === 'application' ? $application : $environments->firstWhere('id', $attempt->target->resourceId);
            if ($resource->trashed()) {
                $resource->restore();
                $application->increment('lifecycle_revision');
            }
            $receipt = new NativeRestorationReceipt($attempt->requestId, (int) $application->lifecycle_revision, $this->states($attempt->target, $application, $environments));
            ResourceRestorationReceipt::query()->create([
                'request_id' => $attempt->requestId,
                'application_id' => $application->getKey(),
                'resource_type' => $attempt->target->resourceType,
                'resource_id' => $attempt->target->resourceId,
                'payload_hash' => $attempt->payloadHash,
                'mapping_fingerprint' => $attempt->mappingFingerprint,
                'expected_revision' => $attempt->expectedRevision,
                'revision' => $receipt->revision,
                'states' => $receipt->toArray()['states'],
                'receipt_hash' => $receipt->hash(),
                'created_at' => now(),
            ]);

            return $receipt;
        }, attempts: 3);
    }

    public function withCurrentReceipt(ResourceRestorationAttempt $attempt, Closure $commit): void
    {
        DB::connection('monitor')->transaction(function () use ($attempt, $commit): void {
            [$application, $environments] = $this->lockSource($attempt->target);
            $this->authorizeAttempt($attempt, $application, $environments);
            $receipt = ResourceRestorationReceipt::query()->where('request_id', $attempt->requestId)->lockForUpdate()->first();
            if ($receipt === null) {
                throw new ResourceRestorationBlocked('native_receipt_missing');
            }
            // Keep the application and child locks until the canonical projection commits.
            $commit($this->verifyReceipt($attempt, $receipt, $application, $environments));
        }, attempts: 3);
    }

    /** @return array{Application, Collection<int, Environment>} */
    private function lockSource(ResourceRestorationTarget $target): array
    {
        if ($target->product !== 'monitor' || ! in_array($target->resourceType, $this->resourceTypes(), true) || $target->sourceWorkspaceEntity !== 'workspace') {
            throw new ResourceRestorationBlocked('source_mapping_changed');
        }
        $applicationId = $target->resourceType === 'application' ? $target->resourceId
            : Environment::withTrashed()->whereKey($target->resourceId)->value('application_id');
        if (DB::connection('monitor')->getDriverName() === 'sqlite') {
            // SQLite ignores FOR UPDATE. Reserve its writer before reading lifecycle
            // state so WAL readers cannot project while another writer rearchives.
            DB::connection('monitor')->table('applications')->where('id', $applicationId)
                ->update(['lifecycle_revision' => DB::raw('lifecycle_revision')]);
        }
        $application = Application::withTrashed()->whereKey($applicationId)->lockForUpdate()->first();
        if ($application === null || (string) $application->workspace_id !== $target->sourceWorkspaceId) {
            throw new ResourceRestorationBlocked('source_mapping_changed');
        }
        if (MonitorDeletionFence::workspaceIsFenced($application->workspace_id)) {
            throw new ResourceRestorationBlocked('source_deleting');
        }
        $workspace = Workspace::query()->whereKey($application->workspace_id)->lockForUpdate()->first();
        if ($workspace === null) {
            throw new ResourceRestorationBlocked('source_mapping_changed');
        }
        $application->setRelation('workspace', $workspace);
        $environments = $application->environments()->withTrashed()->orderBy('id')->lockForUpdate()->get();
        if ($target->resourceType === 'environment' && ! $environments->contains(fn (Environment $environment): bool => (string) $environment->getKey() === $target->resourceId)) {
            throw new ResourceRestorationBlocked('source_mapping_changed');
        }
        if ($target->resourceType === 'environment' && $target->parentApplicationId !== null && $target->parentApplicationId !== (string) $application->getKey()) {
            throw new ResourceRestorationBlocked('source_parent_changed');
        }
        if ($target->resourceType === 'environment' && $application->trashed()) {
            throw new ResourceRestorationBlocked('source_parent_archived');
        }
        foreach ($environments as $environment) {
            if (! in_array($environment->status, ['active', 'paused'], true)) {
                throw new ResourceRestorationBlocked('source_state_invalid');
            }
            $environment->setRelation('application', $application);
        }

        return [$application, $environments];
    }

    private function authorizeAttempt(ResourceRestorationAttempt $attempt, Application $application, Collection $environments): void
    {
        app(ResourceRestorationAuthority::class)->assertAttempt($attempt);
        $actor = PlatformUser::query()->whereKey($attempt->actorId)->where('status', 'active')->first();
        if ($actor === null) {
            throw new ResourceRestorationBlocked('actor_access_changed');
        }
        $this->authorize($actor, $attempt->target, $application, $environments);
    }

    private function authorize(PlatformUser $actor, ResourceRestorationTarget $target, Application $application, Collection $environments): void
    {
        $sourceUsers = $this->identities->sourceIdsFor($actor, 'monitor');
        $nativeManager = $application->workspace->members()->whereIn('users.id', $sourceUsers)->wherePivotIn('role', ['owner', 'admin'])->exists();
        if (! $nativeManager || ! $this->access->application($actor, $application, ProjectResourceAccessPurpose::Restoration)) {
            throw new ResourceRestorationBlocked('actor_access_changed');
        }
        foreach ($environments as $environment) {
            if (($target->resourceType === 'application' || (string) $environment->getKey() === $target->resourceId)
                && ! $this->access->environment($actor, $environment, ProjectResourceAccessPurpose::Restoration)) {
                throw new ResourceRestorationBlocked('actor_access_changed');
            }
        }
    }

    /** @return list<NativeRestorationState> */
    private function states(ResourceRestorationTarget $target, Application $application, Collection $environments, bool $restoring = false): array
    {
        $states = $target->resourceType === 'application' ? [new NativeRestorationState('application', (string) $application->getKey(), $restoring || ! $application->trashed() ? 'active' : 'archived')] : [];
        foreach ($environments as $environment) {
            if ($target->resourceType === 'environment' && (string) $environment->getKey() !== $target->resourceId) {
                continue;
            }
            $archived = $environment->trashed() && ! ($restoring && $target->resourceType === 'environment');
            $states[] = new NativeRestorationState('environment', (string) $environment->getKey(), $archived ? 'archived' : ($environment->status === 'paused' ? 'paused' : 'active'));
        }

        return $states;
    }

    private function verifyReceipt(ResourceRestorationAttempt $attempt, ResourceRestorationReceipt $stored, Application $application, Collection $environments): NativeRestorationReceipt
    {
        if ($stored->payload_hash !== $attempt->payloadHash || $stored->mapping_fingerprint !== $attempt->mappingFingerprint
            || $stored->expected_revision !== $attempt->expectedRevision || $stored->resource_type !== $attempt->target->resourceType
            || $stored->resource_id !== $attempt->target->resourceId || (string) $stored->application_id !== (string) $application->getKey()) {
            throw new ResourceRestorationBlocked('native_receipt_conflict');
        }
        if ($stored->revision !== (int) $application->lifecycle_revision) {
            throw new ResourceRestorationSuperseded;
        }
        $receipt = new NativeRestorationReceipt($attempt->requestId, $stored->revision, $this->states($attempt->target, $application, $environments));
        if (! hash_equals($stored->receipt_hash, $receipt->hash()) || $stored->states !== $receipt->toArray()['states']) {
            throw new ResourceRestorationSuperseded;
        }

        return $receipt;
    }
}
