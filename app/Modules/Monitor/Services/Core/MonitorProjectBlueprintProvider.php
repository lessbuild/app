<?php

namespace App\Modules\Monitor\Services\Core;

use App\Core\Contracts\ProjectBlueprintProvider;
use App\Core\Data\Blueprints\BlueprintProductPreview;
use App\Core\Data\Blueprints\BlueprintProductResult;
use App\Core\Data\Blueprints\BlueprintResource;
use App\Core\Data\Blueprints\BlueprintStepAttempt;
use App\Core\Data\Blueprints\BlueprintTarget;
use App\Core\Exceptions\Blueprints\BlueprintBlocked;
use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\ProjectResource;
use App\Core\Services\Blueprints\BlueprintAuthority;
use App\Core\Services\Blueprints\BlueprintFingerprint;
use App\Core\Services\Blueprints\BlueprintMessages;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\BlueprintApplicationReceipt;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use App\Modules\Monitor\Services\ChangeMonitor;
use App\Modules\Monitor\Services\MonitorPlanAuthority;
use App\Modules\Monitor\Services\PublicHttpTarget;
use App\Modules\Monitor\Services\WorkspacePlanLimits;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class MonitorProjectBlueprintProvider implements ProjectBlueprintProvider
{
    public function __construct(
        private readonly BlueprintAuthority $authority,
        private readonly LegacyIdentityResolver $identities,
        private readonly MonitorPlanAuthority $plans,
        private readonly ChangeMonitor $changeMonitor,
        private readonly PublicHttpTarget $httpTargets,
    ) {}

    public function example(): array
    {
        return [
            'application' => ['name' => 'Example service', 'framework' => 'Laravel', 'framework_version' => null, 'accent' => 'violet'],
            'environments' => [['environment' => 'production']],
            'checks' => [['environment' => 'production', 'name' => 'Health endpoint', 'request_url' => 'https://example.test/health', 'interval_minutes' => 5, 'timeout_seconds' => 5]],
        ];
    }

    public function normalize(array $configuration): array
    {
        Validator::make(['configuration' => $configuration], [
            'configuration' => ['required', 'array:application,environments,checks'],
            'configuration.application' => ['required', 'array:name,framework,framework_version,accent'],
            'configuration.application.name' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'configuration.application.framework' => ['required', 'string', Rule::in(Application::FRAMEWORK_PRESETS)],
            'configuration.application.framework_version' => ['nullable', 'string', 'max:40', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'configuration.application.accent' => ['required', Rule::in(Application::ACCENTS)],
            'configuration.environments' => ['required', 'array', 'min:1', 'max:10'],
            'configuration.environments.*' => ['required', 'array:environment'],
            'configuration.environments.*.environment' => ['required', 'string', 'max:60', 'regex:/\A[a-z][a-z0-9-]*\z/', 'distinct:strict'],
            'configuration.checks' => ['sometimes', 'array', 'max:10'],
            'configuration.checks.*' => ['required', 'array:environment,name,request_url,interval_minutes,timeout_seconds'],
            'configuration.checks.*.environment' => ['required', 'string', 'max:60', 'regex:/\A[a-z][a-z0-9-]*\z/'],
            'configuration.checks.*.name' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'configuration.checks.*.request_url' => ['required', 'string', 'max:2048'],
            'configuration.checks.*.interval_minutes' => ['required', 'integer', Rule::in([1, 5, 15, 30, 60])],
            'configuration.checks.*.timeout_seconds' => ['required', 'integer', 'between:1,20'],
        ])->validate();
        if (! array_is_list($configuration['environments']) || ! array_is_list($configuration['checks'] ?? [])) {
            throw ValidationException::withMessages(['configuration' => __('Use ordered lists for Monitor environments and checks.')]);
        }
        $keys = array_column($configuration['environments'], 'environment');
        if (count($keys) !== count(array_unique($keys))) {
            throw ValidationException::withMessages(['configuration.environments' => __('Each blueprint environment can appear only once.')]);
        }
        $checks = [];
        foreach ($configuration['checks'] ?? [] as $index => $check) {
            $target = $this->httpTargets->parse($check['request_url']);
            $parts = parse_url($check['request_url']);
            if ($target === null || ! is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query'])
                || ! in_array($check['environment'], $keys, true)) {
                throw ValidationException::withMessages(["configuration.checks.{$index}" => __('Checks must use a selected environment and a public health URL without query strings or credentials.')]);
            }
            $checks[] = [
                'environment' => $check['environment'], 'name' => trim($check['name']),
                'request_url' => $check['request_url'], 'interval_minutes' => (int) $check['interval_minutes'],
                'timeout_seconds' => (int) $check['timeout_seconds'],
            ];
        }

        return [
            'application' => [
                'name' => trim($configuration['application']['name']), 'framework' => $configuration['application']['framework'],
                'framework_version' => filled($configuration['application']['framework_version'] ?? null) ? trim($configuration['application']['framework_version']) : null,
                'accent' => $configuration['application']['accent'],
            ],
            'environments' => array_map(fn (array $environment): array => ['environment' => $environment['environment']], $configuration['environments']),
            'checks' => $checks,
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
        if ($attempt->product !== 'monitor') {
            throw new BlueprintBlocked('product_unavailable');
        }
        $configuration = $this->normalize($attempt->configuration);
        $this->assertExactEnvironmentKeys($attempt->target, $configuration);

        return DB::connection('monitor')->transaction(function () use ($attempt, $configuration): BlueprintProductResult {
            $context = $this->context($attempt->target);
            $this->lockWriters($context['actor'], $context['workspace']);
            $this->authority->assertAttempt($attempt);
            $this->assertOpen($context['actor'], $context['workspace']);
            $this->assertNativeAccess($context['actor'], $context['workspace']);

            $receipt = BlueprintApplicationReceipt::query()->where('step_id', $attempt->stepId)->lockForUpdate()->first();
            if ($receipt !== null) {
                $this->assertReceipt($receipt, $attempt, $context, $configuration);
                $this->authority->assertAttempt($attempt);

                return BlueprintProductResult::fromArray(['resources' => $receipt->result['resources'], 'requirements' => $receipt->result['requirements'] ?? []]);
            }

            $state = $this->inspect($attempt->target, $configuration);
            if (! hash_equals(BlueprintFingerprint::make($attempt->nativeAuthority), BlueprintFingerprint::make($state['authority']))) {
                throw new BlueprintBlocked('native_state_changed');
            }
            if ($state['blockers'] !== []) {
                throw new BlueprintBlocked('plan_changed');
            }
            if (! $context['actor']->hasVerifiedEmail() && $configuration['checks'] !== []) {
                throw new BlueprintBlocked('authority_changed');
            }

            if ($state['application'] === null) {
                $this->freshLimits()->assertApplicationCapacity($context['workspace']);
                Gate::forUser($context['actor'])->authorize('create', [Application::class, $context['workspace']]);
                $application = $context['workspace']->applications()->create([
                    'name' => $configuration['application']['name'],
                    'slug' => Str::slug($configuration['application']['name']).'-'.Str::lower(Str::random(12)),
                    ...$configuration['application'],
                ]);
            } else {
                /** @var Application $application */
                $application = Application::query()->whereBelongsTo($context['workspace'])->lockForUpdate()->findOrFail($state['application']->getKey());
                Gate::forUser($context['actor'])->authorize('update', $application);
                $application->forceFill($configuration['application'])->save();
            }

            $resources = [new BlueprintResource('application', (string) $application->getKey(), (string) $application->name)];
            $environmentByKey = [];
            foreach ($state['targets'] as $entry) {
                $environment = $entry['environment'];
                if ($environment === null) {
                    $environment = new Environment;
                    $environment->forceFill([
                        'application_id' => $application->getKey(), 'name' => $entry['binding']['name'],
                        'slug' => $this->environmentSlug($entry['key']), 'status' => 'active',
                        'ingest_token_hash' => hash('sha256', Str::random(64)),
                    ])->save();
                } else {
                    $environment = Environment::query()->whereBelongsTo($application)->lockForUpdate()->findOrFail($environment->getKey());
                    Gate::forUser($context['actor'])->authorize('update', $environment);
                    if ($environment->name !== $entry['binding']['name']) {
                        $environment->forceFill(['name' => $entry['binding']['name']])->save();
                    }
                }
                $environmentByKey[$entry['key']] = $environment;
                $resources[] = new BlueprintResource('environment', (string) $environment->getKey(), (string) $environment->name, $entry['key'], (string) $application->getKey());
            }

            $checkIds = [];
            foreach ($configuration['checks'] as $definition) {
                $environment = $environmentByKey[$definition['environment']] ?? null;
                if (! $environment instanceof Environment || ! Gate::forUser($context['actor'])->allows('create', [Monitor::class, $context['workspace']])) {
                    throw new BlueprintBlocked('native_access_changed');
                }
                $environment = Environment::query()->whereKey($environment->getKey())->lockForUpdate()
                    ->where('status', 'active')->firstOrFail();
                $application = Application::query()->whereKey($environment->application_id)->lockForUpdate()
                    ->where('workspace_id', $context['workspace']->getKey())->whereNull('deleted_at')->firstOrFail();
                if ($this->httpTargets->parse($definition['request_url']) === null) {
                    throw new BlueprintBlocked('native_binding_changed');
                }
                $monitor = $this->changeMonitor->save($context['workspace'], $context['actor'], [
                    'check_type' => 'http', 'environment_id' => $environment->getKey(), 'name' => $definition['name'],
                    'request_url' => $definition['request_url'], 'method' => 'GET', 'status_min' => 200, 'status_max' => 299,
                    'timeout_seconds' => $definition['timeout_seconds'], 'interval_minutes' => $definition['interval_minutes'],
                    'trigger_checks' => 2, 'recovery_checks' => 2, 'enabled' => true, 'destinations' => [],
                    'opened' => true, 'recovered' => true,
                ]);
                $checkIds[] = (string) $monitor->getKey();
            }

            $requirements = [];
            foreach ($environmentByKey as $key => $environment) {
                $requirements[] = __('Issue and securely store an ingestion token for the :environment environment through Monitor.', ['environment' => $attempt->target->environments[$key]['name']]);
            }
            if ($checkIds !== []) {
                $requirements[] = __('Wait for Monitor to run each HTTP check; no check is reported healthy before it runs.');
            }
            $result = new BlueprintProductResult($resources, $requirements);
            $this->authority->assertAttempt($attempt);
            $this->assertOpen($context['actor'], $context['workspace']);
            $this->assertNativeAccess($context['actor'], $context['workspace']);
            $postCapacity = $this->freshLimits()->applicationCapacity($context['workspace']);
            if (! $postCapacity['plan_available'] || ! $postCapacity['limit_configured']
                || ($postCapacity['limit'] !== null && $postCapacity['used'] > $postCapacity['limit'])) {
                throw new BlueprintBlocked('plan_changed');
            }
            $receipt = BlueprintApplicationReceipt::query()->create([
                'step_id' => $attempt->stepId, 'workspace_source_id' => (string) $context['workspace']->getKey(),
                'actor_source_id' => (string) $context['actor']->getKey(), 'canonical_project_id' => $attempt->target->projectId,
                'payload_hash' => $attempt->payloadHash,
                'result' => [...$result->toArray(), 'check_ids' => $checkIds,
                    'configuration_fingerprint' => BlueprintFingerprint::make($configuration)], 'completed_at' => now(),
            ]);
            $this->authority->assertAttempt($attempt);

            return $result;
        }, attempts: 3);
    }

    /** @return array{actor: User, workspace: Workspace} */
    private function context(BlueprintTarget $target): array
    {
        [$coreActor] = $this->authority->authorize($target, 'monitor');
        $actorIds = $this->identities->sourceIdsFor($coreActor, 'monitor');
        $workspaceIds = $this->identities->sourceIdsForCanonical('monitor', 'workspace', $target->workspaceId, 'workspace');
        if (count($actorIds) !== 1 || count($workspaceIds) !== 1) {
            throw new BlueprintBlocked('native_identity_ambiguous');
        }
        $actor = User::query()->find($actorIds[0]);
        $workspace = Workspace::query()->find($workspaceIds[0]);
        if ($actor === null || $workspace === null || ! $workspace->members()->whereKey($actor->getKey())->exists()) {
            throw new BlueprintBlocked('native_access_changed');
        }
        Gate::forUser($actor)->authorize('update', $workspace);

        return ['actor' => $actor, 'workspace' => $workspace];
    }

    /** @return array<string, mixed> */
    private function inspect(BlueprintTarget $target, array $configuration): array
    {
        $context = $this->context($target);
        $workspace = $context['workspace'];
        if (MonitorDeletionFence::workspaceIsFenced($workspace->getKey()) || MonitorDeletionFence::userIsFenced($context['actor']->getKey())) {
            throw new BlueprintBlocked('source_fenced');
        }
        $blockers = [];
        $capacity = $this->freshLimits()->applicationCapacity($workspace);
        if (! $capacity['plan_available'] || ! $capacity['limit_configured']) {
            $blockers[] = __('The current Monitor plan allowance could not be confirmed.');
        }
        $appMappings = ProjectResource::query()->where('project_id', $target->projectId)->where('product', 'monitor')
            ->where('resource_type', 'application')->whereNull('environment_id')->orderBy('id')->get();
        if ($appMappings->count() > 1) {
            $blockers[] = __('More than one Monitor application is linked to this project. Resolve its resource links first.');
        }
        $appMapping = $appMappings->first();
        $application = null;
        if ($appMapping !== null) {
            if ($appMapping->status !== 'active') {
                $blockers[] = __('An archived Monitor application link exists. Restore or remove it before applying.');
            } else {
                $application = Application::withTrashed()->find($appMapping->resource_id);
                if ($application === null || $application->trashed() || (string) $application->workspace_id !== (string) $workspace->getKey()) {
                    $blockers[] = __('The linked Monitor application is archived or belongs to another workspace.');
                    $application = null;
                } elseif (! Gate::forUser($context['actor'])->allows('update', $application)) {
                    $blockers[] = __('Current Monitor access does not allow changing the linked application.');
                }
                if ($application !== null && $this->foreignMappingExists('application', $application->getKey(), $target->projectId)) {
                    $blockers[] = __('The Monitor application is linked to another project. Resolve the resource links first.');
                }
                if ($application !== null && $this->identityConflicts('application', $application->getKey(), 'project', $target->projectId)) {
                    $blockers[] = __('The linked Monitor application has a conflicting project identity. Reconcile it before applying.');
                }
            }
        }
        if ($application === null && ProjectResource::query()->where('project_id', $target->projectId)->where('product', 'monitor')
            ->where('resource_type', 'environment')->whereNotNull('environment_id')->exists()) {
            $blockers[] = __('Monitor environments are linked without one application root. Resolve their project mappings first.');
        }

        $targets = [];
        $seenEnvironmentIds = [];
        foreach ($configuration['environments'] as $entry) {
            $key = $entry['environment'];
            $binding = $target->environments[$key] ?? null;
            if ($binding === null) {
                $blockers[] = __('A Monitor environment key does not match the accepted project environments.');

                continue;
            }
            $mappings = $binding['id'] === null ? collect() : ProjectResource::query()->where('project_id', $target->projectId)
                ->where('product', 'monitor')->where('resource_type', 'environment')->where('environment_id', $binding['id'])->orderBy('id')->get();
            if ($mappings->count() > 1) {
                $blockers[] = __('More than one Monitor environment is linked to a selected project environment. Resolve its resource links first.');

                continue;
            }
            $mapping = $mappings->first();
            $environment = null;
            if ($mapping !== null) {
                if ($mapping->status !== 'active') {
                    $blockers[] = __('An archived Monitor environment link exists. Restore or remove it before applying.');

                    continue;
                }
                $environment = Environment::withTrashed()->find($mapping->resource_id);
                $sourceApp = $environment === null ? null : Application::withTrashed()->find($environment->application_id);
                if ($environment === null || $environment->trashed() || $sourceApp === null || $sourceApp->trashed()
                    || (string) $sourceApp->workspace_id !== (string) $workspace->getKey()) {
                    $blockers[] = __('A selected Monitor environment is archived or belongs to another workspace.');

                    continue;
                }
                if ($application !== null && (string) $environment->application_id !== (string) $application->getKey()) {
                    $blockers[] = __('The selected Monitor environment belongs to a different application.');

                    continue;
                }
                if ($this->identityConflicts('environment', $environment->getKey(), 'project_environment', (string) $binding['id'])) {
                    $blockers[] = __('The selected Monitor environment has a conflicting canonical identity. Reconcile it before applying.');

                    continue;
                }
                if (isset($seenEnvironmentIds[(string) $environment->getKey()]) || $this->foreignMappingExists('environment', $environment->getKey(), $target->projectId, $binding['id'])) {
                    $blockers[] = __('The Monitor environment is already linked elsewhere. Resolve its resource links first.');

                    continue;
                }
                $seenEnvironmentIds[(string) $environment->getKey()] = true;
                if (! Gate::forUser($context['actor'])->allows('update', $environment)) {
                    $blockers[] = __('Current Monitor access does not allow changing a selected environment.');

                    continue;
                }
            }
            $targets[] = ['key' => $key, 'binding' => $binding, 'mapping' => $mapping, 'environment' => $environment];
        }
        $expectedKeys = array_keys($target->environments);
        if (count($configuration['environments']) !== count($expectedKeys)
            || array_diff($expectedKeys, array_column($configuration['environments'], 'environment')) !== []) {
            $blockers[] = __('Monitor configuration must reference every accepted project environment exactly once.');
        }
        if ($application === null && ! $capacity['plan_available']) {
            $blockers[] = __('A Monitor application cannot be created until its plan is confirmed.');
        }
        if ($configuration['checks'] !== [] && ! $context['actor']->hasVerifiedEmail()) {
            $blockers[] = __('Verify the Monitor account before creating uptime checks.');
        }
        if ($configuration['checks'] !== [] && ! Gate::forUser($context['actor'])->allows('create', [Monitor::class, $workspace])) {
            $blockers[] = __('Current Monitor access does not allow creating checks.');
        }
        foreach ($configuration['checks'] as $check) {
            if (! in_array($check['environment'], $expectedKeys, true)) {
                $blockers[] = __('A check references an environment outside this blueprint.');
            }
        }

        $creates = $application === null ? 1 : 0;
        $limit = $capacity['limit'];
        if ($creates > 0 && $capacity['at_limit']) {
            $blockers[] = __('The current Monitor plan has no room for another application.');
        }
        $applicationChanged = $application === null || $application->name !== $configuration['application']['name']
            || $application->framework !== $configuration['application']['framework']
            || $application->framework_version !== $configuration['application']['framework_version']
            || $application->accent !== $configuration['application']['accent'];
        $changes = [$application === null ? __('Create one Monitor application.') : ($applicationChanged
            ? __('Update the linked Monitor application settings.') : __('Keep the linked Monitor application settings unchanged.'))];
        $createEnvironments = count(array_filter($targets, fn (array $entry): bool => $entry['environment'] === null));
        $renameEnvironments = count(array_filter($targets, fn (array $entry): bool => $entry['environment'] !== null
            && $entry['environment']->name !== $entry['binding']['name']));
        if ($createEnvironments > 0) {
            $changes[] = __('Create :count Monitor environment(s).', ['count' => $createEnvironments]);
        }
        if ($renameEnvironments > 0) {
            $changes[] = __('Align :count linked Monitor environment name(s) with the project.', ['count' => $renameEnvironments]);
        }
        if ($createEnvironments === 0 && $renameEnvironments === 0) {
            $changes[] = __('Keep the linked Monitor environments unchanged.');
        }
        if ($configuration['checks'] !== []) {
            $changes[] = __('Create :count HTTP uptime check(s); they remain unknown until Monitor runs them.', ['count' => count($configuration['checks'])]);
        }
        $requirements = array_map(fn (array $entry): string => __('Issue an ingestion token for :environment in Monitor.', [
            'environment' => $target->environments[$entry['environment']]['name'] ?? $entry['environment'],
        ]), $configuration['environments']);
        if ($configuration['checks'] !== []) {
            $requirements[] = __('Monitor must run each new HTTP check before its health can be assessed.');
        }
        $authority = [
            'actor_source_id' => (string) $context['actor']->getKey(), 'workspace_source_id' => (string) $workspace->getKey(),
            'role' => $workspace->roleFor($context['actor']), 'application_capacity' => $capacity,
            'application' => $application === null ? null : ['id' => (string) $application->getKey(), 'name' => $application->name,
                'framework' => $application->framework, 'framework_version' => $application->framework_version, 'accent' => $application->accent],
            'environments' => array_map(fn (array $entry): array => [
                'key' => $entry['key'],
                'mapping_id' => $entry['mapping']?->getKey() === null ? null : (string) $entry['mapping']->getKey(),
                'mapping_status' => $entry['mapping']?->status,
                'id' => $entry['environment']?->getKey() === null ? null : (string) $entry['environment']->getKey(),
                'name' => $entry['environment']?->name, 'slug' => $entry['environment']?->slug,
                'application_id' => $entry['environment']?->application_id,
            ], $targets),
            'configuration_fingerprint' => BlueprintFingerprint::make($configuration),
        ];

        return [
            'application' => $application, 'targets' => $targets, 'blockers' => array_values(array_unique($blockers)),
            'changes' => $changes, 'requirements' => $requirements,
            'planImpact' => ['applications_used' => $capacity['used'], 'applications_limit' => $limit,
                'applications_after' => $capacity['used'] + $creates],
            'authority' => $authority,
        ];
    }

    private function foreignMappingExists(string $type, string|int $sourceId, string $projectId, ?string $environmentId = null): bool
    {
        return ProjectResource::query()->where('product', 'monitor')->where('resource_type', $type)->where('resource_id', (string) $sourceId)
            ->where(fn ($query) => $query->where('project_id', '!=', $projectId)->orWhere('environment_id', '!=', $environmentId))->exists();
    }

    /** Ensure a durable Core attempt still describes every exact target environment once. */
    private function assertExactEnvironmentKeys(BlueprintTarget $target, array $configuration): void
    {
        $configured = array_column($configuration['environments'], 'environment');
        $expected = array_keys($target->environments);
        sort($configured);
        sort($expected);
        if ($configured !== $expected) {
            throw new BlueprintBlocked('native_binding_changed');
        }
    }

    private function identityConflicts(string $entity, string|int $sourceId, string $canonicalEntity, string $canonicalId): bool
    {
        $identity = LegacyIdentityMap::query()->where('source_product', 'monitor')->where('source_entity', $entity)
            ->where('source_id', (string) $sourceId)->first();

        return $identity !== null && ($identity->status !== 'reconciled' || $identity->canonical_entity !== $canonicalEntity
            || (string) $identity->canonical_id !== $canonicalId);
    }

    private function environmentSlug(string $key): string
    {
        return Str::limit(Str::slug($key), 80, '').'-'.Str::lower(Str::random(8));
    }

    private function freshLimits(): WorkspacePlanLimits
    {
        return new WorkspacePlanLimits($this->plans);
    }

    private function lockWriters(User $actor, Workspace $workspace): void
    {
        DB::connection('monitor')->table('users')->where('id', $actor->getKey())->update(['id' => DB::raw('id')]);
        DB::connection('monitor')->table('workspaces')->where('id', $workspace->getKey())->update(['id' => DB::raw('id')]);
    }

    private function assertOpen(User $actor, Workspace $workspace): void
    {
        abort_if(MonitorDeletionFence::lockUser($actor->getKey()), 410, 'Monitor account is being deleted.');
        abort_if(MonitorDeletionFence::lockWorkspace($workspace->getKey()), 410, 'Monitor workspace is being deleted.');
    }

    private function assertNativeAccess(User $actor, Workspace $workspace): void
    {
        if (! $workspace->members()->whereKey($actor->getKey())->exists()) {
            throw new BlueprintBlocked('native_access_changed');
        }
        Gate::forUser($actor)->authorize('update', $workspace);
    }

    /** @param array{actor: User, workspace: Workspace} $context */
    private function assertReceipt(BlueprintApplicationReceipt $receipt, BlueprintStepAttempt $attempt, array $context, array $configuration): void
    {
        if ((string) $receipt->workspace_source_id !== (string) $context['workspace']->getKey()
            || (string) $receipt->actor_source_id !== (string) $context['actor']->getKey()
            || (string) $receipt->canonical_project_id !== $attempt->target->projectId
            || ! hash_equals($receipt->payload_hash, $attempt->payloadHash)
            || ! hash_equals((string) ($receipt->result['configuration_fingerprint'] ?? ''), BlueprintFingerprint::make($configuration))) {
            throw new BlueprintBlocked('receipt_conflict');
        }
        $capacity = $this->freshLimits()->applicationCapacity($context['workspace']);
        if (! $capacity['plan_available'] || ! $capacity['limit_configured']
            || ($capacity['limit'] !== null && $capacity['used'] > $capacity['limit'])) {
            throw new BlueprintBlocked('plan_changed');
        }
        if (count($receipt->result['check_ids'] ?? []) !== count($configuration['checks'])) {
            throw new BlueprintBlocked('receipt_conflict');
        }
        foreach ($receipt->result['resources'] ?? [] as $resource) {
            if (($resource['type'] ?? null) === 'application') {
                $application = Application::query()->where('workspace_id', $context['workspace']->getKey())->find($resource['sourceId'] ?? null);
                if ($application === null || $application->trashed()) {
                    throw new BlueprintBlocked('resource_bindings_changed');
                }
                Gate::forUser($context['actor'])->authorize('view', $application);
                Gate::forUser($context['actor'])->authorize('update', $application);
            } elseif (($resource['type'] ?? null) === 'environment') {
                $environment = Environment::query()->find($resource['sourceId'] ?? null);
                if ($environment === null || $environment->trashed() || (string) $environment->application_id !== (string) ($resource['parentSourceId'] ?? '')) {
                    throw new BlueprintBlocked('resource_bindings_changed');
                }
                Gate::forUser($context['actor'])->authorize('view', $environment);
                Gate::forUser($context['actor'])->authorize('update', $environment);
            }
        }
        foreach ($receipt->result['check_ids'] ?? [] as $id) {
            $monitor = Monitor::query()->whereKey($id)->whereHas('environment', fn ($environment) => $environment->whereHas('application', fn ($application) => $application
                ->where('workspace_id', $context['workspace']->getKey())))->first();
            if ($monitor === null) {
                throw new BlueprintBlocked('resource_bindings_changed');
            }
            Gate::forUser($context['actor'])->authorize('view', $monitor);
            Gate::forUser($context['actor'])->authorize('update', $monitor);
        }
    }
}
