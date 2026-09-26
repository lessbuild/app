<?php

namespace App\Modules\Deployer\Actions\Project;

use App\Core\Exceptions\Blueprints\BlueprintBlocked;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project as CoreProject;
use App\Core\Models\ProjectBlueprintStep;
use App\Core\Models\ProjectEnvironment as CoreProjectEnvironment;
use App\Core\Models\ProjectResource;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Core\Services\Auth\ProductAuthentication;
use App\Core\Services\LegacyIdentityResolver;
use App\Core\Services\WorkspaceProjectAccess;
use App\Modules\Deployer\Models\BlueprintApplicationReceipt;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\EnvironmentBlueprintRecipe;
use App\Modules\Deployer\Models\EnvironmentBlueprintRecipeTombstone;
use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\ProductDeletionFence;
use App\Modules\Deployer\Models\Project;
use App\Modules\Deployer\Models\Recipe;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\Core\DeployerProjectAccess;
use App\Modules\Deployer\Services\Core\DeployerResourceProjection;
use App\Modules\Deployer\Services\EnvironmentBlueprintRecipeTombstoneEvidence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Terminally remove a prepared reference while retaining only signed opaque slot evidence. */
final class ArchiveBlueprintRecipeSnapshotAction
{
    public function __construct(private readonly EnvironmentBlueprintRecipeTombstoneEvidence $evidence) {}

    /**
     * Archive one accepted recipe slot. Repeating the same request verifies its tombstone and succeeds.
     *
     * @return array{already_archived: bool}
     */
    public function handle(
        User $actor,
        Project $project,
        Environment $environment,
        string $stepId,
        int $position,
        bool $confirmed,
    ): array {
        if (! $confirmed || $position < 0 || $position > 65535
            || ! preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $stepId)) {
            abort(422);
        }
        if (! app(ProductAuthentication::class)->usesCoreAuthority('deployer')
            || ! Schema::connection('deployer')->hasTable('environment_blueprint_recipes')
            || ! Schema::connection('deployer')->hasTable('environment_blueprint_recipe_tombstones')) {
            $this->conflict();
        }

        return DB::connection('deployer')->transaction(function () use ($actor, $project, $environment, $stepId, $position): array {
            // Install and archive serialize on the project and share this lock order.
            DB::connection('deployer')->table('users')->where('id', $actor->getKey())->update(['id' => DB::raw('id')]);
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->first();
            if ($lockedActor === null || $lockedActor->current_organization_id === null) {
                $this->conflict();
            }

            DB::connection('deployer')->table('organizations')->where('id', $lockedActor->current_organization_id)
                ->update(['id' => DB::raw('id')]);
            $organization = Organization::query()->whereKey($lockedActor->current_organization_id)->lockForUpdate()->first();
            if ($organization === null) {
                $this->conflict();
            }

            DB::connection('deployer')->table('projects')->where('id', $project->getKey())->update(['id' => DB::raw('id')]);
            $lockedProject = Project::query()->whereKey($project->getKey())->lockForUpdate()->first();
            if ($lockedProject === null || (string) $lockedProject->organization_id !== (string) $organization->getKey()) {
                $this->conflict();
            }

            DB::connection('deployer')->table('environment_blueprint_recipes')
                ->where('step_id', $stepId)->where('environment_id', $environment->getKey())->where('position', $position)
                ->update(['id' => DB::raw('id')]);
            $snapshots = EnvironmentBlueprintRecipe::query()->where('step_id', $stepId)
                ->where('environment_id', $environment->getKey())->where('position', $position)
                ->lockForUpdate()->get();
            if ($snapshots->count() > 1) {
                $this->conflict();
            }
            $snapshot = $snapshots->first();
            $lockedEnvironment = Environment::query()->whereKey($environment->getKey())
                ->where('project_id', $lockedProject->getKey())->lockForUpdate()->first();
            if ($lockedEnvironment === null) {
                $this->conflict();
            }

            $this->assertOpen($lockedActor, $organization);
            $step = ProjectBlueprintStep::query()->whereKey($stepId)->first();
            $receipt = BlueprintApplicationReceipt::query()->where('step_id', $stepId)->lockForUpdate()->first();
            if ($step === null || $receipt === null
                || (string) $receipt->project_source_id !== (string) $lockedProject->getKey()
                || (string) $receipt->workspace_source_id !== (string) $organization->getKey()) {
                $this->conflict();
            }

            $binding = $this->acceptedEnvironmentBinding($step, $receipt, $lockedProject, $lockedEnvironment, $position);
            $this->assertReceiptActorBinding($step, $receipt);
            $this->assertManagerAccess($lockedActor, $organization, $lockedProject, $lockedEnvironment);
            $this->assertExactCoreBindings(
                $lockedActor,
                $organization,
                $lockedProject,
                $lockedEnvironment,
                $binding['canonical_workspace_id'],
                $binding['canonical_project_id'],
                $binding['canonical_environment_id'],
            );

            $tombstone = EnvironmentBlueprintRecipeTombstone::query()->where('step_id', $stepId)
                ->where('environment_id', $lockedEnvironment->getKey())->where('position', $position)
                ->lockForUpdate()->first();
            if ($tombstone !== null) {
                if (! $this->evidence->verifyTombstone($tombstone, $step, $receipt)) {
                    $this->conflict();
                }
                if ($snapshot !== null) {
                    // Two live records for one accepted slot are an integrity conflict, not a repair opportunity.
                    $this->conflict();
                }

                $this->assertOpen($lockedActor, $organization);
                $this->assertManagerAccess($lockedActor, $organization, $lockedProject, $lockedEnvironment);
                $this->assertExactCoreBindings(
                    $lockedActor,
                    $organization,
                    $lockedProject,
                    $lockedEnvironment,
                    $binding['canonical_workspace_id'],
                    $binding['canonical_project_id'],
                    $binding['canonical_environment_id'],
                );

                return ['already_archived' => true];
            }

            if ($snapshot === null) {
                abort(404);
            }
            $tombstone = $this->makeTombstone($step, $receipt, $snapshot);
            if (! hash_equals(
                (string) $tombstone->slot_commitment,
                $this->commitmentForSlot($step, $receipt, $binding['environment_key'], $position),
            )) {
                $this->conflict();
            }
            $this->assertInstallReceipt($snapshot, $organization);

            $tombstone->save();
            $snapshot->delete();
            $tombstone->events()->create([
                'user_id' => $lockedActor->getKey(),
                'category' => 'account',
                'event' => __('A prepared recipe reference was archived.'),
            ]);

            $this->assertOpen($lockedActor, $organization);
            $this->assertManagerAccess($lockedActor, $organization, $lockedProject, $lockedEnvironment);
            $this->assertExactCoreBindings(
                $lockedActor,
                $organization,
                $lockedProject,
                $lockedEnvironment,
                $binding['canonical_workspace_id'],
                $binding['canonical_project_id'],
                $binding['canonical_environment_id'],
            );

            return ['already_archived' => false];
        }, attempts: 3);
    }

    /** @return array{canonical_workspace_id: string, canonical_project_id: string, canonical_environment_id: string, environment_key: string} */
    private function acceptedEnvironmentBinding(
        ProjectBlueprintStep $step,
        BlueprintApplicationReceipt $receipt,
        Project $project,
        Environment $environment,
        int $position,
    ): array {
        $target = $step->target;
        $configuration = $step->configuration;
        if (! is_array($target) || ! is_array($configuration) || ! is_array($configuration['environment_recipes'] ?? null)
            || ! array_is_list($configuration['environment_recipes'])) {
            $this->conflict();
        }
        $targetEnvironments = $target['environments'] ?? null;
        $entries = $configuration['environment_recipes'];
        $resources = $receipt->result['resources'] ?? null;
        $canonicalWorkspaceId = $target['workspaceId'] ?? null;
        $canonicalProjectId = $target['projectId'] ?? null;
        if (! is_array($targetEnvironments) || ! is_array($resources) || ! array_is_list($resources)
            || ! is_string($canonicalWorkspaceId) || $canonicalWorkspaceId === ''
            || ! is_string($canonicalProjectId) || $canonicalProjectId === ''
            || (string) $receipt->canonical_project_id !== $canonicalProjectId) {
            $this->conflict();
        }

        $projectResources = array_values(array_filter($resources, fn (mixed $resource): bool => is_array($resource) && ($resource['type'] ?? null) === 'project'));
        if (count($projectResources) !== 1 || ! is_string($projectResources[0]['sourceId'] ?? null)
            || $projectResources[0]['sourceId'] !== (string) $project->getKey()
            || ($projectResources[0]['name'] ?? null) !== $project->name
            || ($projectResources[0]['environmentKey'] ?? null) !== null
            || ($projectResources[0]['parentSourceId'] ?? null) !== null) {
            $this->conflict();
        }

        $seenEnvironmentKeys = [];
        foreach ($resources as $resource) {
            if (! is_array($resource)) {
                $this->conflict();
            }
            if (($resource['type'] ?? null) === 'project') {
                continue;
            }
            $key = $resource['environmentKey'] ?? null;
            if (($resource['type'] ?? null) !== 'environment' || ! is_string($key)
                || ! array_key_exists($key, $targetEnvironments) || isset($seenEnvironmentKeys[$key])
                || ! is_string($resource['sourceId'] ?? null) || $resource['sourceId'] === ''
                || ($resource['parentSourceId'] ?? null) !== (string) $project->getKey()) {
                $this->conflict();
            }
            $seenEnvironmentKeys[$key] = true;
        }
        if (count($seenEnvironmentKeys) !== count($targetEnvironments)) {
            $this->conflict();
        }

        $matches = [];
        foreach ($entries as $entry) {
            if (! is_array($entry) || ! is_string($entry['environment'] ?? null)
                || ! is_array($entry['recipe_ids'] ?? null) || ! array_is_list($entry['recipe_ids'])
                || ! array_key_exists($position, $entry['recipe_ids'])) {
                continue;
            }
            $environmentKey = $entry['environment'];
            $targetEnvironment = $targetEnvironments[$environmentKey] ?? null;
            if (! is_array($targetEnvironment) || ! is_string($targetEnvironment['id'] ?? null)
                || ! is_string($targetEnvironment['name'] ?? null) || ! is_string($targetEnvironment['type'] ?? null)) {
                continue;
            }
            $resourcesForKey = array_values(array_filter($resources, fn (mixed $resource): bool => is_array($resource) && ($resource['type'] ?? null) === 'environment'
                && ($resource['environmentKey'] ?? null) === $environmentKey));
            if (count($resourcesForKey) !== 1) {
                continue;
            }
            $resource = $resourcesForKey[0];
            if (($resource['sourceId'] ?? null) !== (string) $environment->getKey()) {
                continue;
            }
            if (! is_string($resource['sourceId'] ?? null)
                || ($resource['parentSourceId'] ?? null) !== (string) $project->getKey()
                || ($resource['name'] ?? null) !== $environment->name) {
                $this->conflict();
            }

            $this->commitmentForSlot($step, $receipt, $environmentKey, $position);
            $matches[] = [
                'canonical_workspace_id' => $canonicalWorkspaceId,
                'canonical_project_id' => $canonicalProjectId,
                'canonical_environment_id' => $targetEnvironment['id'],
                'environment_key' => $environmentKey,
            ];
        }
        if (count($matches) !== 1 || $matches[0]['canonical_project_id'] === ''
            || $matches[0]['canonical_environment_id'] === '') {
            $this->conflict();
        }

        return $matches[0];
    }

    private function assertManagerAccess(User $actor, Organization $organization, Project $project, Environment $environment): void
    {
        $actor->setAttribute('current_organization_id', $organization->getKey());
        if ($actor->email_verified_at === null || ! $organization->permits($actor, 'manage')
            || (string) $project->organization_id !== (string) $organization->getKey()
            || ! app(DeployerProjectAccess::class)->project($actor, $project)
            || ! app(DeployerProjectAccess::class)->environment($actor, $environment)
            || ! app(DeployerProjectAccess::class)->canChangeEnvironment($actor, $environment)
            || ! app(DeployerResourceProjection::class)->environments(Environment::query(), $actor)
                ->whereKey($environment->getKey())->exists()) {
            abort(403);
        }
    }

    private function assertReceiptActorBinding(ProjectBlueprintStep $step, BlueprintApplicationReceipt $receipt): void
    {
        $target = $step->target;
        if (! is_array($target) || ! is_string($target['actorId'] ?? null)) {
            $this->conflict();
        }
        $canonicalReceiptActorId = app(LegacyIdentityResolver::class)->canonicalIdForSource(
            'deployer', 'user', (string) $receipt->actor_source_id, 'user',
        );
        if ((string) $canonicalReceiptActorId !== $target['actorId']) {
            $this->conflict();
        }
    }

    private function assertExactCoreBindings(
        User $actor,
        Organization $organization,
        Project $project,
        Environment $environment,
        string $canonicalWorkspaceId,
        string $canonicalProjectId,
        string $canonicalEnvironmentId,
    ): void {
        $identities = app(LegacyIdentityResolver::class);
        $platformActorId = $identities->canonicalIdForSource('deployer', 'user', (string) $actor->getKey(), 'user');
        $platformActor = $platformActorId === null
            ? null : PlatformUser::query()->whereKey($platformActorId)->where('status', 'active')->first();
        $actorSourceIds = $platformActor === null
            ? [] : $identities->sourceIdsForCanonical('deployer', 'user', (string) $platformActor->getKey(), 'user');
        $mappedCanonicalWorkspaceId = $identities->canonicalIdForSource(
            'deployer', 'organization', (string) $organization->getKey(), 'workspace',
        );
        $canonicalWorkspace = $mappedCanonicalWorkspaceId === null ? null : CoreWorkspace::query()
            ->whereKey($mappedCanonicalWorkspaceId)->where('status', 'active')->whereNull('archived_at')->first();
        $workspaceSourceIds = $canonicalWorkspace === null ? [] : $identities->sourceIdsForCanonical(
            'deployer', 'organization', (string) $canonicalWorkspace->getKey(), 'workspace',
        );
        $canonicalProject = $canonicalWorkspace === null ? null : CoreProject::query()
            ->whereKey($canonicalProjectId)->where('workspace_id', $canonicalWorkspace->getKey())
            ->where('status', 'active')->whereNull('archived_at')->first();
        $canonicalEnvironment = $canonicalProject === null ? null : CoreProjectEnvironment::query()
            ->whereKey($canonicalEnvironmentId)->where('project_id', $canonicalProject->getKey())
            ->where('status', 'active')->where('environment_type', $environment->type)->first();
        $coreAccess = app(WorkspaceProjectAccess::class);
        if ($platformActor === null || count($actorSourceIds) !== 1
            || (string) $actorSourceIds[0] !== (string) $actor->getKey()
            || $canonicalWorkspace === null || count($workspaceSourceIds) !== 1
            || (string) $workspaceSourceIds[0] !== (string) $organization->getKey()
            || (string) $canonicalWorkspace->getKey() !== $canonicalWorkspaceId
            || $canonicalProject === null || $canonicalEnvironment === null
            || ! $coreAccess->canManageWorkspace($platformActor, $canonicalWorkspace)
            || ! $coreAccess->accessibleProductProjects($platformActor, $canonicalWorkspace, 'deployer')
                ->whereKey($canonicalProject->getKey())->exists()) {
            $this->conflict();
        }

        // Query every status in both directions so dormant or conflicting claims also block archive.
        $projectMappings = ProjectResource::query()->where('product', 'deployer')->where('resource_type', 'project')
            ->where('project_id', $canonicalProjectId)->get();
        if ($projectMappings->count() !== 1 || $projectMappings->first()->status !== 'active'
            || (string) $projectMappings->first()->resource_id !== (string) $project->getKey()
            || $projectMappings->first()->environment_id !== null) {
            $this->conflict();
        }
        $otherProjectMappings = ProjectResource::query()->where('product', 'deployer')->where('resource_type', 'project')
            ->where('resource_id', (string) $project->getKey())->get();
        if ($otherProjectMappings->count() !== 1
            || (string) $otherProjectMappings->first()->getKey() !== (string) $projectMappings->first()->getKey()) {
            $this->conflict();
        }

        $environmentMappings = ProjectResource::query()->where('product', 'deployer')->where('resource_type', 'environment')
            ->where('environment_id', $canonicalEnvironmentId)->get();
        if ($environmentMappings->count() !== 1 || $environmentMappings->first()->status !== 'active'
            || (string) $environmentMappings->first()->resource_id !== (string) $environment->getKey()) {
            $this->conflict();
        }
        $otherEnvironmentMappings = ProjectResource::query()->where('product', 'deployer')->where('resource_type', 'environment')
            ->where('resource_id', (string) $environment->getKey())->get();
        if ($otherEnvironmentMappings->count() !== 1
            || (string) $otherEnvironmentMappings->first()->getKey() !== (string) $environmentMappings->first()->getKey()) {
            $this->conflict();
        }
        if ((string) $canonicalProject->workspace_id !== (string) $canonicalWorkspace->getKey()
            || (string) $canonicalEnvironment->project_id !== (string) $canonicalProject->getKey()
            || (string) $projectMappings->first()->project_id !== (string) $canonicalProject->getKey()
            || (string) $environmentMappings->first()->project_id !== (string) $canonicalProject->getKey()
            || (string) $environmentMappings->first()->environment_id !== (string) $canonicalEnvironment->getKey()) {
            $this->conflict();
        }
    }

    private function assertInstallReceipt(EnvironmentBlueprintRecipe $snapshot, Organization $organization): void
    {
        $hasReceipt = $snapshot->installed_recipe_id !== null
            || $snapshot->install_attempted_at !== null
            || $snapshot->install_receipt_fingerprint !== null;
        if (! $hasReceipt) {
            return;
        }
        if ($snapshot->installed_recipe_id === null || $snapshot->install_attempted_at === null
            || $snapshot->install_receipt_fingerprint === null) {
            $this->conflict();
        }
        $key = $this->fingerprintKey();
        $expected = hash_hmac('sha256', json_encode([
            'purpose' => 'blueprint-recipe-snapshot-install',
            'binding_fingerprint' => (string) $snapshot->binding_fingerprint,
            'workspace_id' => (string) $organization->getKey(),
            'recipe_id' => (string) $snapshot->installed_recipe_id,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $key);
        if (! hash_equals((string) $snapshot->install_receipt_fingerprint, $expected)) {
            $this->conflict();
        }

        $copy = Recipe::query()->whereKey($snapshot->installed_recipe_id)
            ->where('organization_id', $organization->getKey())->lockForUpdate()->first();
        if ($copy === null) {
            $this->conflict();
        }
    }

    private function commitmentForSlot(ProjectBlueprintStep $step, BlueprintApplicationReceipt $receipt, string $environmentKey, int $position): string
    {
        try {
            return $this->evidence->commitmentForSlot($step, $receipt, $environmentKey, $position);
        } catch (BlueprintBlocked) {
            $this->conflict();
        }
    }

    private function makeTombstone(
        ProjectBlueprintStep $step,
        BlueprintApplicationReceipt $receipt,
        EnvironmentBlueprintRecipe $snapshot,
    ): EnvironmentBlueprintRecipeTombstone {
        try {
            return $this->evidence->makeTombstone($step, $receipt, $snapshot);
        } catch (BlueprintBlocked) {
            $this->conflict();
        }
    }

    private function assertOpen(User $actor, Organization $organization): void
    {
        $fenced = ProductDeletionFence::query()->where(function ($query) use ($actor, $organization): void {
            $query->where(fn ($account) => $account->where('kind', 'account')->where('source_id', (string) $actor->getKey()))
                ->orWhere(fn ($workspace) => $workspace->where('kind', 'workspace')->where('source_id', (string) $organization->getKey()));
        })->exists();
        if ($fenced) {
            abort(409);
        }
    }

    private function fingerprintKey(): string
    {
        $key = trim((string) config('app.key'));
        if ($key === '') {
            $this->conflict();
        }

        return $key;
    }

    private function conflict(): never
    {
        throw new HttpException(409, __('The prepared recipe reference is stale or has changed.'));
    }
}
