<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Contracts\ProjectBlueprintProvider;
use App\Core\Data\Blueprints\BlueprintProductPreview;
use App\Core\Data\Blueprints\BlueprintProductResult;
use App\Core\Data\Blueprints\BlueprintResource;
use App\Core\Data\Blueprints\BlueprintStepAttempt;
use App\Core\Data\Blueprints\BlueprintTarget;
use App\Core\Exceptions\Blueprints\BlueprintBlocked;
use App\Core\Models\ProjectResource;
use App\Core\Services\Blueprints\BlueprintAuthority;
use App\Core\Services\Blueprints\BlueprintFingerprint;
use App\Core\Services\Blueprints\BlueprintMessages;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Deployer\Actions\Project\ApplyApplicationTemplate;
use App\Modules\Deployer\Models\BlueprintApplicationReceipt;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\EnvironmentProcess;
use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\ProductDeletionFence;
use App\Modules\Deployer\Models\Project;
use App\Modules\Deployer\Models\Recipe;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\ApplicationTemplateCatalog;
use App\Modules\Deployer\Services\Entitlements;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class DeployerProjectBlueprintProvider implements ProjectBlueprintProvider
{
    public function __construct(
        private readonly BlueprintAuthority $authority,
        private readonly LegacyIdentityResolver $identities,
        private readonly ApplicationTemplateCatalog $templates,
        private readonly Entitlements $entitlements,
        private readonly ApplyApplicationTemplate $applyTemplate,
    ) {}

    public function example(): array
    {
        $template = in_array('laravel', $this->templates->keys(), true) ? 'laravel' : ($this->templates->keys()[0] ?? '');

        return ['schema_version' => 1, 'project_name' => 'Checkout', 'template' => $template,
            'environment_recipes' => []];
    }

    public function normalize(array $configuration): array
    {
        Validator::make(['configuration' => $configuration], [
            'configuration' => ['required', 'array:schema_version,project_name,template,environment_recipes'],
            'configuration.schema_version' => ['required', 'integer', Rule::in([1])],
            'configuration.project_name' => ['required', 'string', 'max:100'],
            'configuration.template' => ['required', 'string', Rule::in($this->templates->keys())],
            'configuration.environment_recipes' => ['sometimes', 'array', 'max:10'],
            'configuration.environment_recipes.*' => ['required', 'array:environment,recipe_ids'],
            'configuration.environment_recipes.*.environment' => ['required', 'string', 'max:60', 'regex:/\A[a-z][a-z0-9-]*\z/', 'distinct:strict'],
            'configuration.environment_recipes.*.recipe_ids' => ['present', 'array', 'max:20'],
            'configuration.environment_recipes.*.recipe_ids.*' => ['required', 'integer', 'min:1', 'distinct:strict'],
        ])->validate();
        if (! array_is_list($configuration['environment_recipes'] ?? [])) {
            throw ValidationException::withMessages(['configuration.environment_recipes' => __('Use an ordered list of recipe references.')]);
        }

        $projectName = trim($configuration['project_name']);
        if ($projectName === '') {
            throw ValidationException::withMessages(['configuration.project_name' => __('Use a project name with at least one non-space character.')]);
        }

        $recipes = [];
        foreach ($configuration['environment_recipes'] ?? [] as $entry) {
            if (! array_is_list($entry['recipe_ids'])) {
                throw ValidationException::withMessages(['configuration.environment_recipes' => __('Use an ordered list of recipe references.')]);
            }
            $recipes[] = ['environment' => $entry['environment'], 'recipe_ids' => array_map('intval', $entry['recipe_ids'])];
        }

        return [
            'schema_version' => 1,
            'project_name' => $projectName,
            'template' => $configuration['template'],
            'environment_recipes' => $recipes,
        ];
    }

    public function preview(BlueprintTarget $target, array $configuration): BlueprintProductPreview
    {
        $configuration = $this->normalize($configuration);
        try {
            $state = $this->inspect($target, $configuration);
        } catch (BlueprintBlocked $exception) {
            return new BlueprintProductPreview(blockers: [BlueprintMessages::reason($exception->reason)]);
        }

        return new BlueprintProductPreview(
            changes: $state['changes'], requirements: $state['requirements'], blockers: $state['blockers'],
            planImpact: $state['planImpact'], authority: $state['authority'],
        );
    }

    public function apply(BlueprintStepAttempt $attempt): BlueprintProductResult
    {
        $this->authority->assertAttempt($attempt);
        abort_unless($attempt->product === 'deployer', 409);
        $configuration = $this->normalize($attempt->configuration);

        return DB::connection('deployer')->transaction(function () use ($attempt, $configuration): BlueprintProductResult {
            $context = $this->context($attempt->target);
            $context = $this->reserveAndLock($context['actor'], $context['organization'], $context['project']);
            $this->assertIdentityPair($attempt->target, $context);
            $this->authority->assertAttempt($attempt);
            $this->assertOpen($context['actor'], $context['organization']);
            $this->assertNativeAccess($context['actor'], $context['organization'], $context['project']);

            $receipt = BlueprintApplicationReceipt::query()->where('step_id', $attempt->stepId)->lockForUpdate()->first();
            if ($receipt !== null) {
                if ($context['project'] === null) {
                    $context['project'] = Project::query()->whereKey($receipt->project_source_id)
                        ->where('organization_id', $context['organization']->getKey())->first();
                    if ($context['project'] === null) {
                        throw new BlueprintBlocked('resource_conflict');
                    }
                    $context = $this->reserveAndLock($context['actor'], $context['organization'], $context['project']);
                    $this->assertIdentityPair($attempt->target, $context);
                    $this->authority->assertAttempt($attempt);
                }
                $this->assertReceipt($receipt, $attempt, $context);
                $this->assertReplayState($attempt, $configuration, $receipt, $context);
                $this->authority->assertAttempt($attempt);

                return BlueprintProductResult::fromArray($receipt->result);
            }

            $state = $this->inspect($attempt->target, $configuration);
            if (! hash_equals(BlueprintFingerprint::make($attempt->nativeAuthority), BlueprintFingerprint::make($state['authority']))) {
                throw new BlueprintBlocked('native_state_changed');
            }
            if ($state['blockers'] !== []) {
                throw new BlueprintBlocked('plan_changed');
            }

            $template = $this->templates->for($configuration['template']);
            if ($context['project'] === null) {
                if (! $context['organization']->permits($context['actor'], 'deploy')) {
                    throw new BlueprintBlocked('native_access_changed');
                }
                $context['project'] = $context['organization']->projects()->create([
                    'name' => $configuration['project_name'], 'slug' => $this->projectSlug($context['organization'], $configuration['project_name']),
                    ...$this->applyTemplate->projectAttributes($template), 'created_by' => $context['actor']->getKey(),
                ]);
            } else {
                $context['project']->forceFill([
                    'name' => $configuration['project_name'], ...$this->applyTemplate->projectAttributes($template),
                ])->save();
            }

            $resources = [new BlueprintResource('project', (string) $context['project']->getKey(), (string) $context['project']->name)];
            $requirements = [];
            foreach ($state['environments'] as $entry) {
                /** @var Environment|null $environment */
                $environment = $entry['model'];
                if ($environment === null) {
                    $environment = $this->applyTemplate->createEnvironment($context['project'], [
                        'name' => $entry['definition']['name'], 'slug' => $entry['key'], 'type' => $entry['definition']['type'],
                    ], $template);
                } else {
                    $this->applyTemplate->configureEnvironment($environment, [
                        'name' => $entry['definition']['name'], 'type' => $entry['definition']['type'],
                    ], $template);
                }

                $this->applyTemplate->configureProcesses($environment, $template, $state['workersAllowed']);

                $resources[] = new BlueprintResource('environment', (string) $environment->getKey(), (string) $environment->name, $entry['key'], (string) $context['project']->getKey());
                $requirements[] = __('Connect a repository and provide environment secrets for :environment.', ['environment' => $environment->name]);
                if (! $state['workersAllowed'] && $template->processes !== []) {
                    $requirements[] = __('Add the template’s background processes for :environment after enabling the workers feature.', ['environment' => $environment->name]);
                }
                foreach ($entry['recipes'] as $recipe) {
                    $requirements[] = __('Review and install recipe :recipe through Deployer for :environment; scripts are not run by blueprint setup.', [
                        'recipe' => $recipe->name, 'environment' => $environment->name,
                    ]);
                }
            }
            $requirements[] = __('Review deployment settings, supply credentials, and run the first deployment manually.');

            $result = new BlueprintProductResult($resources, array_values(array_unique($requirements)));
            $this->authority->assertAttempt($attempt);
            $this->assertOpen($context['actor'], $context['organization']);
            $this->assertNativeAccess($context['actor'], $context['organization'], $context['project']);
            $this->assertTemplateAndPlan($attempt, $configuration, $context);
            $this->assertEnvironmentPermissions($state['environments'], $context);
            $this->receipt($attempt, $context, $result);
            $this->authority->assertAttempt($attempt);

            return $result;
        }, attempts: 3);
    }

    /** @return array{actor: User, organization: Organization, project: ?Project} */
    private function context(BlueprintTarget $target): array
    {
        [$platformActor] = $this->authority->authorize($target, 'deployer');
        $actors = $this->identities->sourceIdsFor($platformActor, 'deployer');
        $organizations = $this->identities->sourceIdsForCanonical('deployer', 'organization', $target->workspaceId, 'workspace');
        if (count($actors) !== 1 || count($organizations) !== 1) {
            throw new BlueprintBlocked('native_identity_ambiguous');
        }
        $actor = User::query()->find($actors[0]);
        $organization = Organization::query()->find($organizations[0]);
        $projectMappings = ProjectResource::query()->where('project_id', $target->projectId)->where('product', 'deployer')
            ->where('resource_type', 'project')->get();
        if ($projectMappings->count() > 1 || ($projectMappings->count() === 1 && $projectMappings->first()->status !== 'active')) {
            throw new BlueprintBlocked('native_binding_changed');
        }
        $project = null;
        if ($projectMappings->count() === 1) {
            $projectMapping = $projectMappings->first();
            if (ProjectResource::query()->where('product', 'deployer')->where('resource_type', 'project')
                ->where('resource_id', $projectMapping->resource_id)->where('status', 'active')
                ->where('project_id', '!=', $target->projectId)->exists()) {
                throw new BlueprintBlocked('native_binding_changed');
            }
            $project = Project::query()->find($projectMapping->resource_id);
            if ($project === null) {
                throw new BlueprintBlocked('native_binding_changed');
            }
        }
        if (! $actor || ! $organization || ($project !== null && (string) $project->organization_id !== (string) $organization->getKey())) {
            throw new BlueprintBlocked('native_access_changed');
        }

        return ['actor' => $actor, 'organization' => $organization, 'project' => $project];
    }

    /** @return array<string, mixed> */
    private function inspect(BlueprintTarget $target, array $configuration): array
    {
        $context = $this->context($target);
        $actor = $context['actor'];
        $organization = $context['organization'];
        $project = $context['project'];
        $this->assertNativeAccess($actor, $organization, $project);
        $this->assertOpen($actor, $organization);
        $template = $this->templates->for($configuration['template']);
        $workersAllowed = $this->entitlements->allows($organization, 'workers');
        $blockers = [];
        $changes = [];
        $requirements = [];
        $environments = [];
        $authorityEnvironments = [];
        if (! in_array($template->runtimeType, Environment::RUNTIME_TYPES, true)
            || ($template->version() !== null && mb_strlen($template->version()) > 32)
            || ($template->dockerfilePath !== null && mb_strlen($template->dockerfilePath) > 255)) {
            $blockers[] = __('The selected Deployer template contains runtime metadata that cannot be stored safely.');
        }
        if ($workersAllowed) {
            foreach ($template->processes as $process) {
                if (! $this->validProcess($process)) {
                    $blockers[] = __('The selected Deployer template has an invalid background process definition.');
                    break;
                }
            }
        }

        if ($project === null) {
            $changes[] = __('Create Deployer project :project using the selected template.', ['project' => $configuration['project_name']]);
        } else {
            if (trim($configuration['project_name']) !== $project->name) {
                $changes[] = __('Rename Deployer project from :old to :new.', ['old' => $project->name, 'new' => $configuration['project_name']]);
            }
            if ($project->preset !== $template->key || $project->template_version !== $template->version()) {
                $changes[] = __('Record the selected Deployer template version for the project.');
            }
        }

        foreach ($target->environments as $key => $definition) {
            if (! preg_match('/\A[a-z][a-z0-9-]*\z/', $key) || ! in_array($definition['type'], Environment::TYPES, true)) {
                $blockers[] = __('Deployer cannot configure an environment with an unsupported key or type.');

                continue;
            }
            $model = null;
            $mappingId = null;
            if ($definition['id'] !== null) {
                $allEnvironmentMappings = ProjectResource::query()->where('product', 'deployer')
                    ->where('resource_type', 'environment')->where('environment_id', $definition['id'])->get();
                $mappings = ProjectResource::query()->where('project_id', $target->projectId)->where('product', 'deployer')
                    ->where('resource_type', 'environment')->where('environment_id', $definition['id'])->get();
                if ($allEnvironmentMappings->count() !== $mappings->count()) {
                    $blockers[] = __('A selected Deployer environment is mapped under a different Core project.');

                    continue;
                }
                if ($mappings->count() > 1 || ($mappings->count() === 1 && $mappings->first()->status !== 'active')) {
                    $blockers[] = __('A selected Deployer environment has no unique active resource mapping.');

                    continue;
                }
                if ($mappings->count() === 1) {
                    $mapping = $mappings->first();
                    $mappingId = (string) $mapping->getKey();
                    if (ProjectResource::query()->where('product', 'deployer')->where('resource_type', 'environment')
                        ->where('resource_id', $mapping->resource_id)->where('status', 'active')
                        ->where('project_id', '!=', $target->projectId)->exists()) {
                        $blockers[] = __('A selected Deployer environment is also linked to another project.');

                        continue;
                    }
                    $environmentQuery = Environment::query()->whereKey($mapping->resource_id);
                    if (DB::connection('deployer')->transactionLevel() > 0) {
                        $environmentQuery->lockForUpdate();
                    }
                    $model = $environmentQuery->first();
                    if (! $model || $project === null || (string) $model->project_id !== (string) $project->getKey() || $model->type !== $definition['type']) {
                        $blockers[] = __('A selected Deployer environment is missing or belongs to another project.');

                        continue;
                    }
                    if (! $this->canChangeEnvironment($actor, $organization, $model)) {
                        $blockers[] = __('This Deployer environment is shared with a resource that cannot be changed from this project.');

                        continue;
                    }
                    if (($model->is_protected || $model->requires_deployment_approval)
                        && ! $organization->permits($actor, 'manage')) {
                        $blockers[] = __('Managing a protected Deployer environment requires a workspace owner or administrator.');

                        continue;
                    }
                }
                if ($model === null && $project !== null
                    && Environment::query()->where('project_id', $project->getKey())->where('slug', $key)->exists()) {
                    $blockers[] = __('A Deployer environment with key :key already exists without an active Core resource mapping.', ['key' => $key]);

                    continue;
                }
            } else {
                $model = $project === null ? null : Environment::query()->where('project_id', $project->getKey())->where('slug', $key)->first();
                if ($model !== null) {
                    $blockers[] = __('A Deployer environment with key :key already exists without an active Core resource mapping.', ['key' => $key]);

                    continue;
                }
            }
            if ($model === null && $definition['type'] === 'production' && ! $organization->permits($actor, 'manage')) {
                $blockers[] = __('Creating a production Deployer environment requires a workspace owner or administrator.');

                continue;
            }

            $recipeDefinitions = collect($configuration['environment_recipes'])->firstWhere('environment', $key);
            $recipes = [];
            foreach ($recipeDefinitions['recipe_ids'] ?? [] as $recipeId) {
                $recipeQuery = Recipe::query()->whereKey($recipeId);
                if (DB::connection('deployer')->transactionLevel() > 0) {
                    $recipeQuery->lockForUpdate();
                }
                $recipe = $recipeQuery->first();
                if (! $recipe || ! $this->canUseRecipe($recipe, $actor, $organization)) {
                    $blockers[] = __('A selected Deployer recipe is missing or is not available to this workspace.');

                    continue;
                }
                $recipes[] = $recipe;
            }
            $envAttributes = [
                'name' => $definition['name'], 'type' => $definition['type'],
                'runtime_type' => $template->runtimeType, 'build_command' => $template->buildCommand,
                'start_command' => $template->startCommand, 'container_port' => $template->containerPort,
                'dockerfile_path' => $template->dockerfilePath,
            ];
            $environments[] = ['key' => $key, 'definition' => $definition, 'model' => $model, 'recipes' => $recipes];
            $authorityEnvironments[] = [
                'key' => $key, 'mapping_id' => $mappingId,
                'source_id' => $model?->getKey(), 'type' => $definition['type'], 'name' => $definition['name'],
                'state' => $model ? $this->environmentState($model) : null,
                'recipes' => collect($recipes)->map(fn (Recipe $recipe): array => [
                    'id' => (string) $recipe->getKey(), 'name' => $recipe->name,
                    'organization_id' => $recipe->organization_id ? (string) $recipe->organization_id : null,
                    'user_id' => $recipe->user_id ? (string) $recipe->user_id : null,
                    'updated_at' => $recipe->updated_at?->toISOString(),
                ])->all(),
                'desired' => [
                    'name' => $envAttributes['name'], 'type' => $envAttributes['type'],
                    'runtime_type' => $envAttributes['runtime_type'],
                    'build_command_hash' => BlueprintFingerprint::make($envAttributes['build_command']),
                    'start_command_hash' => BlueprintFingerprint::make($envAttributes['start_command']),
                    'container_port' => $envAttributes['container_port'], 'dockerfile_path' => $envAttributes['dockerfile_path'],
                ],
            ];
            $changes[] = $model
                ? __('Configure existing Deployer environment :environment.', ['environment' => $definition['name']])
                : __('Create Deployer environment :environment.', ['environment' => $definition['name']]);
        }
        foreach ($configuration['environment_recipes'] as $recipeConfig) {
            if (! array_key_exists($recipeConfig['environment'], $target->environments)) {
                $blockers[] = __('A recipe reference uses an environment key that is not in this blueprint target.');
            }
        }
        if (! $workersAllowed && $template->processes !== []) {
            $requirements[] = __('The selected template defines background processes, but this workspace plan does not allow workers.');
        }
        $requirements[] = __('Repository connection, secrets, infrastructure selection, and the first deployment require manual review.');
        foreach ($environments as $entry) {
            foreach ($entry['recipes'] as $recipe) {
                $requirements[] = __('Review and install recipe :recipe through Deployer; blueprint setup never runs recipe scripts.', ['recipe' => $recipe->name]);
            }
        }

        $authority = [
            'actor_source_id' => (string) $actor->getKey(), 'organization_source_id' => (string) $organization->getKey(),
            'project_source_id' => $project ? (string) $project->getKey() : null,
            'project_name' => $project?->name, 'project_preset' => $project?->preset, 'project_template_version' => $project?->template_version,
            'project_mapping' => $this->projectMappingState($target),
            'role' => $organization->roleFor($actor), 'workers_allowed' => $workersAllowed,
            'template' => $configuration['template'], 'template_version' => $template->version(),
            'template_runtime' => [$template->runtimeType, BlueprintFingerprint::make($template->buildCommand),
                BlueprintFingerprint::make($template->startCommand), $template->containerPort, $template->dockerfilePath],
            'processes_hash' => BlueprintFingerprint::make($template->processes),
            'environments' => $authorityEnvironments,
        ];

        return [
            'changes' => $changes, 'requirements' => array_values(array_unique($requirements)), 'blockers' => array_values(array_unique($blockers)),
            'planImpact' => ['environments_to_create' => count(array_filter($environments, fn ($entry): bool => $entry['model'] === null)),
                'environments_to_update' => count(array_filter($environments, fn ($entry): bool => $entry['model'] !== null)),
                'project_limit' => null, 'workers_allowed' => $workersAllowed ? 1 : 0],
            'authority' => $authority, 'context' => $context, 'environments' => $environments, 'workersAllowed' => $workersAllowed,
        ];
    }

    private function assertNativeAccess(User $actor, Organization $organization, ?Project $project): void
    {
        $actor->setAttribute('current_organization_id', $organization->getKey());
        $membershipQuery = DB::connection('deployer')->table('organization_user')
            ->where('organization_id', $organization->getKey())->where('user_id', $actor->getKey());
        if (DB::connection('deployer')->transactionLevel() > 0) {
            $membershipQuery->lockForUpdate();
        }
        $membership = $membershipQuery->first();
        if ($membership === null || $actor->email_verified_at === null || ! $organization->permits($actor, 'deploy')
            || ($project !== null && (string) $project->organization_id !== (string) $organization->getKey())) {
            throw new BlueprintBlocked('native_access_changed');
        }
    }

    private function canChangeEnvironment(User $actor, Organization $organization, Environment $environment): bool
    {
        $actor->setAttribute('current_organization_id', $organization->getKey());

        return app(DeployerProjectAccess::class)->canChangeEnvironment($actor, $environment);
    }

    private function assertEnvironmentPermissions(array $environments, array $context): void
    {
        foreach ($environments as $entry) {
            if (! $entry['model'] instanceof Environment) {
                continue;
            }
            $environment = Environment::query()->whereKey($entry['model']->getKey())->lockForUpdate()->first();
            if (! $environment || ! $this->canChangeEnvironment($context['actor'], $context['organization'], $environment)) {
                throw new BlueprintBlocked('resource_conflict');
            }
            if (($environment->is_protected || $environment->requires_deployment_approval)
                && ! $context['organization']->permits($context['actor'], 'manage')) {
                throw new BlueprintBlocked('native_access_changed');
            }
        }
    }

    private function assertTemplateAndPlan(BlueprintStepAttempt $attempt, array $configuration, array $context): void
    {
        $template = $this->templates->for($configuration['template']);
        $authority = $attempt->nativeAuthority;
        if ((bool) ($authority['workers_allowed'] ?? false) !== $this->entitlements->allows($context['organization'], 'workers')
            || ($authority['template_version'] ?? null) !== $template->version()
            || ($authority['template_runtime'] ?? null) !== [
                $template->runtimeType, BlueprintFingerprint::make($template->buildCommand),
                BlueprintFingerprint::make($template->startCommand), $template->containerPort, $template->dockerfilePath,
            ]
            || ($authority['processes_hash'] ?? null) !== BlueprintFingerprint::make($template->processes)) {
            throw new BlueprintBlocked('plan_changed');
        }
    }

    private function assertIdentityPair(BlueprintTarget $target, array $context): void
    {
        $actors = $this->identities->sourceIdsFor($target->actorId, 'deployer');
        $organizations = $this->identities->sourceIdsForCanonical('deployer', 'organization', $target->workspaceId, 'workspace');
        if (count($actors) !== 1 || count($organizations) !== 1
            || (string) $actors[0] !== (string) $context['actor']->getKey()
            || (string) $organizations[0] !== (string) $context['organization']->getKey()) {
            throw new BlueprintBlocked('native_identity_ambiguous');
        }
    }

    private function assertOpen(User $actor, Organization $organization): void
    {
        $fenced = ProductDeletionFence::query()->where(function ($query) use ($actor, $organization): void {
            $query->where(fn ($account) => $account->where('kind', 'account')->where('source_id', (string) $actor->getKey()))
                ->orWhere(fn ($workspace) => $workspace->where('kind', 'workspace')->where('source_id', (string) $organization->getKey()));
        })->exists();
        if ($fenced) {
            throw new BlueprintBlocked('source_fenced');
        }
    }

    private function canUseRecipe(Recipe $recipe, User $actor, Organization $organization): bool
    {
        return $recipe->organization_id !== null
            ? (string) $recipe->organization_id === (string) $organization->getKey() && $organization->permits($actor, 'deploy')
            : (string) $recipe->user_id === (string) $actor->getKey();
    }

    private function validProcess(mixed $definition): bool
    {
        return is_array($definition)
            && is_string($definition['name'] ?? null) && trim($definition['name']) !== ''
            && is_string($definition['command'] ?? null) && trim($definition['command']) !== ''
            && in_array($definition['type'] ?? null, EnvironmentProcess::TYPES, true);
    }

    private function reserveAndLock(User $actor, Organization $organization, ?Project $project): array
    {
        foreach ([['users', $actor->getKey()], ['organizations', $organization->getKey()]] as [$table, $id]) {
            DB::connection('deployer')->table($table)->where('id', $id)->update(['id' => DB::raw('id')]);
        }
        $actor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
        $organization = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
        if ($project !== null) {
            DB::connection('deployer')->table('projects')->where('id', $project->getKey())->update(['id' => DB::raw('id')]);
            $project = Project::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();
        }

        return ['actor' => $actor, 'organization' => $organization, 'project' => $project];
    }

    private function assertReceipt(BlueprintApplicationReceipt $receipt, BlueprintStepAttempt $attempt, array $context): void
    {
        if ($receipt->payload_hash !== $attempt->payloadHash
            || $receipt->canonical_project_id !== $attempt->target->projectId
            || (string) $receipt->workspace_source_id !== (string) $context['organization']->getKey()
            || (string) $receipt->actor_source_id !== (string) $context['actor']->getKey()
            || (string) $receipt->project_source_id !== (string) $context['project']?->getKey()) {
            throw new BlueprintBlocked('resource_conflict');
        }
        $this->assertOpen($context['actor'], $context['organization']);
        $this->assertNativeAccess($context['actor'], $context['organization'], $context['project']);
    }

    private function assertReplayState(BlueprintStepAttempt $attempt, array $configuration, BlueprintApplicationReceipt $receipt, array $context): void
    {
        $this->assertOpen($context['actor'], $context['organization']);
        $this->assertNativeAccess($context['actor'], $context['organization'], $context['project']);
        $this->assertTemplateAndPlan($attempt, $configuration, $context);

        $savedResult = BlueprintProductResult::fromArray($receipt->result);
        $expectedKeys = array_fill_keys(array_keys($attempt->target->environments), true);
        $seenKeys = [];
        $projectResources = 0;
        foreach ($savedResult->resources as $resource) {
            if ($resource->type === 'project') {
                if ((string) $resource->sourceId !== (string) $context['project']->getKey()
                    || $resource->name !== $context['project']->name) {
                    throw new BlueprintBlocked('resource_conflict');
                }
                $projectResources++;

                continue;
            }
            if ($resource->type !== 'environment' || ! isset($expectedKeys[$resource->environmentKey])
                || (string) $resource->parentSourceId !== (string) $context['project']->getKey()) {
                throw new BlueprintBlocked('invalid_product_result');
            }
            $environmentQuery = Environment::query()->whereKey($resource->sourceId)
                ->where('project_id', $context['project']->getKey());
            if (DB::connection('deployer')->transactionLevel() > 0) {
                $environmentQuery->lockForUpdate();
            }
            $environment = $environmentQuery->first();
            if (! $environment || $environment->name !== $resource->name) {
                throw new BlueprintBlocked('resource_conflict');
            }
            if (! $this->canChangeEnvironment($context['actor'], $context['organization'], $environment)) {
                throw new BlueprintBlocked('resource_conflict');
            }
            if (($environment->is_protected || $environment->requires_deployment_approval)
                && ! $context['organization']->permits($context['actor'], 'manage')) {
                throw new BlueprintBlocked('native_access_changed');
            }
            $seenKeys[$resource->environmentKey] = true;
        }
        if ($projectResources !== 1 || array_diff_key($expectedKeys, $seenKeys) !== []) {
            throw new BlueprintBlocked('invalid_product_result');
        }

        foreach ($configuration['environment_recipes'] as $entry) {
            foreach ($entry['recipe_ids'] as $recipeId) {
                $recipe = Recipe::query()->find($recipeId);
                if (! $recipe || ! $this->canUseRecipe($recipe, $context['actor'], $context['organization'])) {
                    throw new BlueprintBlocked('native_access_changed');
                }
            }
        }
    }

    private function receipt(BlueprintStepAttempt $attempt, array $context, BlueprintProductResult $result): void
    {
        BlueprintApplicationReceipt::query()->create([
            'step_id' => $attempt->stepId, 'workspace_source_id' => (string) $context['organization']->getKey(),
            'actor_source_id' => (string) $context['actor']->getKey(), 'project_source_id' => (string) $context['project']->getKey(),
            'canonical_project_id' => $attempt->target->projectId, 'payload_hash' => $attempt->payloadHash,
            'result' => $result->toArray(), 'completed_at' => now(),
        ]);
    }

    private function projectMappingState(BlueprintTarget $target): array
    {
        return ProjectResource::query()->where('project_id', $target->projectId)->where('product', 'deployer')
            ->where('resource_type', 'project')->orderBy('id')->get(['id', 'resource_id', 'status'])
            ->map(fn (ProjectResource $mapping): array => [(string) $mapping->getKey(), (string) $mapping->resource_id, $mapping->status])->all();
    }

    private function environmentState(Environment $environment): array
    {
        return [
            'id' => (string) $environment->getKey(), 'name' => $environment->name, 'slug' => $environment->slug,
            'type' => $environment->type, 'branch' => $environment->branch,
            'runtime_type' => $environment->runtime_type, 'runtime_version' => $environment->runtime_version,
            'build_command_hash' => is_string($environment->build_command) ? BlueprintFingerprint::make($environment->build_command) : null,
            'start_command_hash' => is_string($environment->start_command) ? BlueprintFingerprint::make($environment->start_command) : null,
            'processes' => $environment->processes()->orderBy('id')->get()->map(fn (EnvironmentProcess $process): array => [
                'id' => (string) $process->getKey(), 'name' => $process->name, 'type' => $process->type,
                'command_hash' => BlueprintFingerprint::make($process->command),
                'replicas' => $process->replicas, 'restart_policy' => $process->restart_policy,
                'restart_delay_seconds' => $process->restart_delay_seconds, 'is_enabled' => $process->is_enabled,
            ])->all(),
        ];
    }

    private function projectSlug(Organization $organization, string $name): string
    {
        $base = Str::slug($name) ?: 'application';
        $slug = $base;
        $suffix = 2;
        while (Project::query()->where('organization_id', $organization->getKey())->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
