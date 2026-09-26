<?php

namespace App\Modules\Deployer\Actions\Project;

use App\Core\Models\PlatformUser;
use App\Core\Models\Project as CoreProject;
use App\Core\Models\ProjectEnvironment as CoreProjectEnvironment;
use App\Core\Models\ProjectResource;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Core\Services\Auth\ProductAuthentication;
use App\Core\Services\LegacyIdentityResolver;
use App\Core\Services\WorkspaceProjectAccess;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\EnvironmentBlueprintRecipe;
use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\ProductDeletionFence;
use App\Modules\Deployer\Models\Project;
use App\Modules\Deployer\Models\Recipe;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\ActivityRecorder;
use App\Modules\Deployer\Services\Core\DeployerProjectAccess;
use App\Modules\Deployer\Services\Core\DeployerResourceProjection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/** Copy one authorized immutable blueprint snapshot into its destination workspace recipe library. */
final class InstallBlueprintRecipeSnapshotAction
{
    public function __construct(private readonly ActivityRecorder $activity) {}

    /**
     * Copy the stored snapshot once. This action never reads the live source recipe, attaches servers, or runs scripts.
     *
     * @param  bool  $sharePersonalSnapshot  Whether the personal snapshot owner explicitly consents to workspace sharing.
     */
    public function handle(
        User $actor,
        Project $project,
        Environment $environment,
        EnvironmentBlueprintRecipe $snapshot,
        bool $sharePersonalSnapshot = false,
    ): Recipe {
        if (! app(ProductAuthentication::class)->usesCoreAuthority('deployer')) {
            $this->conflict();
        }
        if (! Schema::connection('deployer')->hasTable('environment_blueprint_recipes')) {
            $this->conflict();
        }

        $copy = DB::connection('deployer')->transaction(function () use (
            $actor, $project, $environment, $snapshot, $sharePersonalSnapshot,
        ): Recipe {
            // A write fence serializes concurrent first copies on databases where SELECT FOR UPDATE is advisory.
            DB::connection('deployer')->table('environment_blueprint_recipes')
                ->where('id', $snapshot->getKey())->update(['id' => DB::raw('id')]);

            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->first();
            $lockedProject = Project::query()->whereKey($project->getKey())->lockForUpdate()->first();
            if ($lockedActor === null || $lockedProject === null) {
                $this->conflict();
            }
            $lockedEnvironment = Environment::query()->whereKey($environment->getKey())
                ->where('project_id', $lockedProject->getKey())->lockForUpdate()->first();
            $lockedSnapshot = EnvironmentBlueprintRecipe::query()->whereKey($snapshot->getKey())
                ->where('environment_id', $environment->getKey())->lockForUpdate()->first();
            if ($lockedEnvironment === null || $lockedSnapshot === null) {
                $this->conflict();
            }

            $organization = Organization::query()->whereKey($lockedProject->organization_id)->lockForUpdate()->first();
            if ($organization === null || (string) $lockedActor->current_organization_id !== (string) $organization->getKey()
                || (string) $lockedSnapshot->workspace_source_id !== (string) $organization->getKey()
                || (string) $lockedSnapshot->environment_id !== (string) $lockedEnvironment->getKey()
                || (string) $lockedEnvironment->project_id !== (string) $lockedProject->getKey()
                || (string) $lockedSnapshot->canonical_project_id === ''
                || (string) $lockedSnapshot->canonical_environment_id === '') {
                $this->conflict();
            }

            $this->assertOpen($lockedActor, $organization);
            $this->assertAuthorized($lockedActor, $lockedProject, $lockedEnvironment, $organization, $lockedSnapshot);
            $this->assertExactCoreBindings($lockedActor, $organization, $lockedProject, $lockedEnvironment, $lockedSnapshot);
            $this->assertSnapshotIntegrity($lockedSnapshot);

            if ($lockedSnapshot->source_organization_id === null) {
                if ((string) $lockedSnapshot->source_user_id !== (string) $lockedActor->getKey()
                    || (string) $lockedSnapshot->actor_source_id !== (string) $lockedActor->getKey()) {
                    abort(403);
                }
                if (! $sharePersonalSnapshot) {
                    abort(422, __('Confirm that this personal recipe snapshot will be copied into the shared workspace library.'));
                }
            } elseif ((string) $lockedSnapshot->source_organization_id !== (string) $organization->getKey()) {
                abort(403);
            }

            $hasReceipt = $lockedSnapshot->installed_recipe_id !== null
                || $lockedSnapshot->install_attempted_at !== null
                || $lockedSnapshot->install_receipt_fingerprint !== null;
            if ($hasReceipt) {
                if ($lockedSnapshot->installed_recipe_id === null || $lockedSnapshot->install_attempted_at === null
                    || $lockedSnapshot->install_receipt_fingerprint === null) {
                    $this->conflict();
                }
                $expectedReceipt = $this->receiptFingerprint(
                    (string) $lockedSnapshot->binding_fingerprint,
                    (string) $organization->getKey(),
                    (string) $lockedSnapshot->installed_recipe_id,
                    $this->fingerprintKey(),
                );
                if (! hash_equals((string) $lockedSnapshot->install_receipt_fingerprint, $expectedReceipt)) {
                    $this->conflict();
                }
                $existing = Recipe::query()->whereKey($lockedSnapshot->installed_recipe_id)
                    ->where('organization_id', $organization->getKey())->first();
                if ($existing === null) {
                    $this->conflict();
                }

                $this->assertAuthorized($lockedActor, $lockedProject, $lockedEnvironment, $organization, $lockedSnapshot);
                $this->assertExactCoreBindings($lockedActor, $organization, $lockedProject, $lockedEnvironment, $lockedSnapshot);
                $this->assertOpen($lockedActor, $organization);

                return $existing;
            }

            $script = (string) $lockedSnapshot->script_snapshot;
            $copy = new Recipe;
            $copy->forceFill([
                'user_id' => $lockedActor->getKey(),
                'organization_id' => $organization->getKey(),
                'name' => $lockedSnapshot->source_name,
                'description' => $lockedSnapshot->source_description,
                'script' => $script,
                'is_published' => false,
                'category' => null,
                'published_at' => null,
                'gallery_revision_at' => null,
                'source_recipe_id' => null,
                'source_revision_at' => null,
                'install_count' => 0,
            ])->save();

            $key = $this->fingerprintKey();
            $lockedSnapshot->forceFill([
                'installed_recipe_id' => $copy->getKey(),
                'install_receipt_fingerprint' => $this->receiptFingerprint(
                    (string) $lockedSnapshot->binding_fingerprint,
                    (string) $organization->getKey(),
                    (string) $copy->getKey(),
                    $key,
                ),
                'install_attempted_at' => now(),
            ])->save();

            $this->activity->record(
                $copy,
                $lockedActor->getKey(),
                'recipe',
                __('Prepared blueprint recipe snapshot was copied into the workspace library.'),
            );
            $this->assertAuthorized($lockedActor, $lockedProject, $lockedEnvironment, $organization, $lockedSnapshot);
            $this->assertExactCoreBindings($lockedActor, $organization, $lockedProject, $lockedEnvironment, $lockedSnapshot);
            $this->assertOpen($lockedActor, $organization);

            return $copy;
        }, attempts: 3);

        return $copy;
    }

    private function assertAuthorized(
        User $actor,
        Project $project,
        Environment $environment,
        Organization $organization,
        EnvironmentBlueprintRecipe $snapshot,
    ): void {
        $actor->setAttribute('current_organization_id', $organization->getKey());
        if ($actor->email_verified_at === null || ! $organization->permits($actor, 'view') || ! $organization->permits($actor, 'deploy')
            || (string) $project->organization_id !== (string) $organization->getKey()
            || ! app(DeployerProjectAccess::class)->project($actor, $project)
            || ! app(DeployerProjectAccess::class)->environment($actor, $environment)
            || ! app(DeployerProjectAccess::class)->canChangeEnvironment($actor, $environment)) {
            abort(403);
        }
        if (($environment->is_protected || $environment->requires_deployment_approval)
            && ! $organization->permits($actor, 'manage')) {
            abort(403);
        }

        if (! app(DeployerResourceProjection::class)->environments(Environment::query(), $actor)
            ->whereKey($environment->getKey())->exists()) {
            abort(404);
        }

        if ((string) $snapshot->canonical_project_id === '' || (string) $snapshot->canonical_environment_id === '') {
            $this->conflict();
        }
    }

    private function assertExactCoreBindings(
        User $actor,
        Organization $organization,
        Project $project,
        Environment $environment,
        EnvironmentBlueprintRecipe $snapshot,
    ): void {
        $identities = app(LegacyIdentityResolver::class);
        $platformActorId = $identities->canonicalIdForSource('deployer', 'user', (string) $actor->getKey(), 'user');
        $platformActor = $platformActorId === null
            ? null : PlatformUser::query()->whereKey($platformActorId)->where('status', 'active')->first();
        $actorSourceIds = $platformActor === null
            ? [] : $identities->sourceIdsForCanonical('deployer', 'user', (string) $platformActor->getKey(), 'user');
        $canonicalWorkspaceId = $identities->canonicalIdForSource(
            'deployer', 'organization', (string) $organization->getKey(), 'workspace',
        );
        $canonicalWorkspace = $canonicalWorkspaceId === null ? null : CoreWorkspace::query()
            ->whereKey($canonicalWorkspaceId)->where('status', 'active')->whereNull('archived_at')->first();
        $workspaceSourceIds = $canonicalWorkspace === null ? [] : $identities->sourceIdsForCanonical(
            'deployer', 'organization', (string) $canonicalWorkspace->getKey(), 'workspace',
        );
        $canonicalProject = $canonicalWorkspace === null ? null : CoreProject::query()
            ->whereKey($snapshot->canonical_project_id)->where('workspace_id', $canonicalWorkspace->getKey())
            ->where('status', 'active')->whereNull('archived_at')->first();
        $canonicalEnvironment = $canonicalProject === null ? null : CoreProjectEnvironment::query()
            ->whereKey($snapshot->canonical_environment_id)->where('project_id', $canonicalProject->getKey())
            ->where('status', 'active')->where('environment_type', $environment->type)->first();
        if ($platformActor === null || count($actorSourceIds) !== 1
            || (string) $actorSourceIds[0] !== (string) $actor->getKey()
            || $canonicalWorkspace === null || count($workspaceSourceIds) !== 1
            || (string) $workspaceSourceIds[0] !== (string) $organization->getKey()
            || $canonicalProject === null || $canonicalEnvironment === null
            || ! app(WorkspaceProjectAccess::class)->accessibleProductProjects($platformActor, $canonicalWorkspace, 'deployer')
                ->whereKey($canonicalProject->getKey())->exists()) {
            $this->conflict();
        }

        $projectMappings = ProjectResource::query()->where('product', 'deployer')->where('resource_type', 'project')
            ->where('project_id', $snapshot->canonical_project_id)->get();
        if ($projectMappings->count() !== 1 || $projectMappings->first()->status !== 'active'
            || (string) $projectMappings->first()->resource_id !== (string) $project->getKey()) {
            $this->conflict();
        }
        $otherProjectMappings = ProjectResource::query()->where('product', 'deployer')->where('resource_type', 'project')
            ->where('resource_id', (string) $project->getKey())->get();
        if ($otherProjectMappings->count() !== 1
            || (string) $otherProjectMappings->first()->getKey() !== (string) $projectMappings->first()->getKey()) {
            $this->conflict();
        }

        $environmentMappings = ProjectResource::query()->where('product', 'deployer')->where('resource_type', 'environment')
            ->where('environment_id', $snapshot->canonical_environment_id)->get();
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
        if ((string) $canonicalProject->getKey() !== (string) $snapshot->canonical_project_id
            || (string) $canonicalEnvironment->getKey() !== (string) $snapshot->canonical_environment_id
            || (string) $canonicalProject->workspace_id !== (string) $canonicalWorkspace->getKey()
            || (string) $canonicalEnvironment->project_id !== (string) $canonicalProject->getKey()
            || (string) $projectMappings->first()->project_id !== (string) $canonicalProject->getKey()
            || (string) $environmentMappings->first()->project_id !== (string) $canonicalProject->getKey()
            || (string) $environmentMappings->first()->environment_id !== (string) $canonicalEnvironment->getKey()) {
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

    private function assertSnapshotIntegrity(EnvironmentBlueprintRecipe $snapshot): void
    {
        try {
            $key = $this->fingerprintKey();
            $script = (string) $snapshot->script_snapshot;
            $scriptFingerprint = hash_hmac('sha256', $script, $key);
            $bindingFingerprint = hash_hmac('sha256', json_encode(
                $this->normalizedSnapshotAttributes($snapshot),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ), $key);
        } catch (Throwable) {
            $this->conflict();
        }
        if (! hash_equals((string) $snapshot->script_fingerprint, $scriptFingerprint)
            || ! hash_equals((string) $snapshot->binding_fingerprint, $bindingFingerprint)) {
            $this->conflict();
        }
    }

    /** @return array<string, mixed> */
    private function normalizedSnapshotAttributes(EnvironmentBlueprintRecipe $snapshot): array
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

    private function receiptFingerprint(string $bindingFingerprint, string $workspaceId, string $recipeId, string $key): string
    {
        return hash_hmac('sha256', json_encode([
            'purpose' => 'blueprint-recipe-snapshot-install',
            'binding_fingerprint' => $bindingFingerprint,
            'workspace_id' => $workspaceId,
            'recipe_id' => $recipeId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $key);
    }

    private function fingerprintKey(): string
    {
        $key = trim((string) config('app.key'));
        if ($key === '') {
            $this->conflict();
        }

        return $key;
    }

    private function timestamp(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : (string) $value;
    }

    private function conflict(): never
    {
        throw new HttpException(409, __('The prepared recipe reference is stale or has changed.'));
    }
}
