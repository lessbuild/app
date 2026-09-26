<?php

namespace App\Modules\Analytics\Services\Core;

use App\Core\Contracts\ProjectBlueprintProvider;
use App\Core\Data\Blueprints\BlueprintProductPreview;
use App\Core\Data\Blueprints\BlueprintProductResult;
use App\Core\Data\Blueprints\BlueprintResource;
use App\Core\Data\Blueprints\BlueprintStepAttempt;
use App\Core\Data\Blueprints\BlueprintTarget;
use App\Core\Exceptions\Blueprints\BlueprintBlocked;
use App\Core\Models\PlatformUser;
use App\Core\Models\ProjectResource;
use App\Core\Services\Blueprints\BlueprintAuthority;
use App\Core\Services\Blueprints\BlueprintFingerprint;
use App\Core\Services\Blueprints\BlueprintMessages;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Analytics\Actions\Sites\CreateSiteForWorkspace;
use App\Modules\Analytics\Enums\WorkspaceRole;
use App\Modules\Analytics\Models\BlueprintApplicationReceipt;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Models\User as AnalyticsUser;
use App\Modules\Analytics\Models\Workspace;
use App\Modules\Analytics\Policies\SitePolicy;
use App\Modules\Analytics\Services\AnalyticsPlanAuthority;
use App\Modules\Analytics\Services\AnalyticsWorkspaceAccess;
use App\Modules\Analytics\Services\Deletion\AnalyticsDeletionFence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AnalyticsProjectBlueprintProvider implements ProjectBlueprintProvider
{
    public function __construct(
        private readonly BlueprintAuthority $authority,
        private readonly LegacyIdentityResolver $identities,
        private readonly AnalyticsWorkspaceAccess $access,
        private readonly AnalyticsPlanAuthority $plans,
        private readonly CreateSiteForWorkspace $createSite,
        private readonly AnalyticsDeletionFence $fence,
    ) {}

    public function example(): array
    {
        return ['sites' => [[
            'environment' => 'production', 'name' => 'Marketing site',
            'domains' => ['www.example.com'], 'timezone' => 'UTC',
        ]]];
    }

    public function normalize(array $configuration): array
    {
        Validator::make(['configuration' => $configuration], [
            'configuration' => ['required', 'array:sites'],
            'configuration.sites' => ['required', 'array', 'min:1', 'max:10'],
            'configuration.sites.*' => ['required', 'array:environment,name,domains,timezone'],
            'configuration.sites.*.environment' => ['required', 'string', 'max:60', 'regex:/\A[a-z][a-z0-9-]*\z/', 'distinct:strict'],
            'configuration.sites.*.name' => ['required', 'string', 'max:120'],
            'configuration.sites.*.domains' => ['required', 'array', 'min:1', 'max:20'],
            'configuration.sites.*.domains.*' => ['required', 'string', 'max:255'],
            'configuration.sites.*.timezone' => ['required', 'timezone'],
        ])->validate();
        if (! array_is_list($configuration['sites'])) {
            throw ValidationException::withMessages(['configuration.sites' => __('Use an ordered list of Analytics sites.')]);
        }

        $sites = [];
        foreach ($configuration['sites'] as $index => $site) {
            $domains = collect($site['domains'])->map(fn (string $domain): string => $this->normalizeDomain($domain))->unique()->values()->all();
            if ($domains === [] || collect($domains)->contains(fn (string $domain): bool => ! $this->validDomain($domain))) {
                throw ValidationException::withMessages(["configuration.sites.{$index}.domains" => __('Use one or more valid hostnames.')]);
            }
            $sites[] = [
                'environment' => $site['environment'], 'name' => trim($site['name']),
                'domains' => $domains, 'timezone' => $site['timezone'],
            ];
        }

        return ['sites' => $sites];
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
        abort_unless($attempt->product === 'analytics', 409);
        $configuration = $this->normalize($attempt->configuration);
        $this->assertEnvironmentScope($attempt->target, $configuration);

        return DB::connection('analytics')->transaction(function () use ($attempt, $configuration): BlueprintProductResult {
            $context = $this->context($attempt->target);
            $this->lockSourceRows($context['nativeActor'], $context['workspace']);
            $this->authority->assertAttempt($attempt);
            $this->fence->assertWorkspaceOpen($context['workspace']->getKey());
            $this->fence->assertAccountOpen($context['nativeActor']->getKey());
            $this->assertCurrentNativeAccess($attempt->target, $context['actor'], $context['nativeActor'], $context['workspace']);

            $receipt = BlueprintApplicationReceipt::query()->where('step_id', $attempt->stepId)->lockForUpdate()->first();
            if ($receipt !== null) {
                $this->assertReceipt($receipt, $attempt, $context);
                $this->authority->assertAttempt($attempt);

                return BlueprintProductResult::fromArray($receipt->result);
            }

            $state = $this->inspect($attempt->target, $configuration);
            if (! hash_equals(BlueprintFingerprint::make($attempt->nativeAuthority), BlueprintFingerprint::make($state['authority']))) {
                throw new BlueprintBlocked('native_binding_changed');
            }
            if ($state['blockers'] !== []) {
                throw new BlueprintBlocked('plan_changed');
            }

            $resources = [];
            $requirements = [];
            foreach ($state['targets'] as $entry) {
                /** @var Site|null $site */
                $site = $entry['site'];
                if ($site === null) {
                    $currentPlan = $this->plans->resolve($context['workspace']);
                    if (! $currentPlan->available || ! $currentPlan->allows('site_management') || ! $currentPlan->hasLimit('sites')
                        || ($currentPlan->limit('sites') !== null && $context['workspace']->sites()->count() >= $currentPlan->limit('sites'))) {
                        throw new BlueprintBlocked('plan_changed');
                    }
                    $site = $this->createSite->handle($context['workspace'], $currentPlan, [
                        'name' => $entry['definition']['name'],
                        'slug' => Str::slug($entry['definition']['name']).'-'.Str::lower(Str::random(8)),
                        'domains' => $entry['definition']['domains'],
                        'timezone' => $entry['definition']['timezone'],
                    ]);
                } else {
                    $site = Site::query()->where('workspace_id', $context['workspace']->getKey())
                        ->whereKey($site->getKey())->lockForUpdate()->firstOrFail();
                    $this->fence->assertSiteOpen($site->getKey());
                    abort_unless(app(SitePolicy::class)->manage($context['actor'], $site), 403);
                    $this->fence->assertWorkspaceOpen($context['workspace']->getKey());
                    if ($site->events()->exists() && $site->timezone !== $entry['definition']['timezone']) {
                        throw new BlueprintBlocked('timezone_conflict');
                    }
                    $domainsChanged = ($site->domains ?? []) !== $entry['definition']['domains'];
                    $site->update([
                        'name' => $entry['definition']['name'], 'domains' => $entry['definition']['domains'],
                        'timezone' => $entry['definition']['timezone'], 'verified_at' => $domainsChanged ? null : $site->verified_at,
                    ]);
                }
                $this->fence->assertSiteOpen($site->getKey());
                $resources[] = new BlueprintResource('site', (string) $site->getKey(), (string) $site->name, $entry['definition']['environment']);
                if (! $site->isVerified()) {
                    $requirements[] = __('Complete DNS verification and install the Analytics tracker for :site.', ['site' => $site->name]);
                } else {
                    $requirements[] = __('Install or confirm the Analytics tracker for :site.', ['site' => $site->name]);
                }
            }

            $result = new BlueprintProductResult($resources, array_values(array_unique($requirements)));
            $this->authority->assertAttempt($attempt);
            $this->fence->assertWorkspaceOpen($context['workspace']->getKey());
            $this->fence->assertAccountOpen($context['nativeActor']->getKey());
            $this->assertCurrentNativeAccess($attempt->target, $context['actor'], $context['nativeActor'], $context['workspace']);
            $receipt = BlueprintApplicationReceipt::query()->create([
                'step_id' => $attempt->stepId,
                'workspace_source_id' => (string) $context['workspace']->getKey(),
                'actor_source_id' => (string) $context['nativeActor']->getKey(),
                'canonical_project_id' => $attempt->target->projectId,
                'payload_hash' => $attempt->payloadHash,
                'result' => $result->toArray(),
                'completed_at' => now(),
            ]);
            $this->authority->assertAttempt($attempt);

            return $result;
        }, attempts: 3);
    }

    /** @return array{actor: PlatformUser, nativeActor: AnalyticsUser, workspace: Workspace} */
    private function context(BlueprintTarget $target): array
    {
        [$actor] = $this->authority->authorize($target, 'analytics');
        $actorIds = $this->identities->sourceIdsFor($actor, 'analytics');
        $workspaceIds = $this->identities->sourceIdsForCanonical('analytics', 'workspace', $target->workspaceId, 'workspace');
        if (count($actorIds) !== 1 || count($workspaceIds) !== 1) {
            throw new BlueprintBlocked('native_binding_changed');
        }
        $nativeActor = AnalyticsUser::query()->find($actorIds[0]);
        $workspace = Workspace::query()->find($workspaceIds[0]);
        if ($nativeActor === null || $workspace === null) {
            throw new BlueprintBlocked('authority_changed');
        }
        $this->fence->assertWorkspaceOpen($workspace->getKey());
        $this->fence->assertAccountOpen($nativeActor->getKey());
        if (! $this->access->hasAccess($actor, $workspace)) {
            throw new BlueprintBlocked('authority_changed');
        }
        $role = $workspace->roleFor($nativeActor->getKey());
        if (! in_array($role, [WorkspaceRole::Owner, WorkspaceRole::Admin], true)) {
            throw new BlueprintBlocked('authority_changed');
        }

        return ['actor' => $actor, 'nativeActor' => $nativeActor, 'workspace' => $workspace];
    }

    /** @return array<string, mixed> */
    private function inspect(BlueprintTarget $target, array $configuration): array
    {
        $this->assertEnvironmentScope($target, $configuration);
        $context = $this->context($target);
        $workspace = $context['workspace'];
        $plan = $this->plans->resolve($workspace);
        $blockers = [];
        if (! $plan->available || ! $plan->allows('site_management') || ! $plan->hasLimit('sites')) {
            $blockers[] = __('The current Analytics plan does not allow site management or has no confirmed site allowance.');
        }
        $targets = [];
        $createCount = 0;
        $updateCount = 0;
        $reuseCount = 0;
        $seenSites = [];
        $authoritySites = [];
        foreach ($configuration['sites'] as $definition) {
            $key = $definition['environment'];
            $binding = $target->environments[$key] ?? null;
            if ($binding === null) {
                $blockers[] = __('Choose a project environment for each Analytics site.');

                continue;
            }
            $mappingQuery = ProjectResource::query()->where('project_id', $target->projectId)->where('product', 'analytics')
                ->where('resource_type', 'site');
            // A not-yet-created canonical environment has no resource scope yet.
            // Do not adopt legacy/unassigned mappings: the saved run will bind a
            // newly created environment before native apply begins.
            $mappings = $binding['id'] === null
                ? collect()
                : $mappingQuery->where('environment_id', $binding['id'])->orderBy('id')->get();
            if ($mappings->count() > 1) {
                $blockers[] = __('More than one Analytics site is linked to a selected project environment. Resolve the resource links first.');

                continue;
            }
            $mapping = $mappings->first();
            $site = null;
            if ($mapping !== null) {
                if ($mapping->status !== 'active') {
                    $blockers[] = __('An archived Analytics resource link exists for a selected environment. Restore or remove that link first.');

                    continue;
                }
                $site = Site::withTrashed()->find($mapping->resource_id);
                if ($site === null || $site->trashed() || (string) $site->workspace_id !== (string) $workspace->getKey()) {
                    $blockers[] = __('A selected Analytics resource link is archived or belongs to another workspace. Resolve it before applying.');

                    continue;
                }
                if (! app(SitePolicy::class)->manage($context['actor'], $site)) {
                    $blockers[] = __('You no longer have Analytics management access to a selected site.');

                    continue;
                }
                if (isset($seenSites[(string) $site->getKey()])) {
                    $blockers[] = __('A single Analytics site is linked to more than one selected environment. Resolve the resource links first.');

                    continue;
                }
                $seenSites[(string) $site->getKey()] = true;
                if ($site->events()->exists() && $site->timezone !== $definition['timezone']) {
                    $blockers[] = __('A selected Analytics site already has events and cannot change reporting timezone.');

                    continue;
                }
                if ($site->name === $definition['name'] && ($site->domains ?? []) === $definition['domains'] && $site->timezone === $definition['timezone']) {
                    $reuseCount++;
                } else {
                    $updateCount++;
                }
            } else {
                $createCount++;
            }
            $targets[] = ['definition' => $definition, 'site' => $site];
            $authoritySites[] = [
                'environment_key' => $key,
                'mapping_id' => $mapping?->getKey() === null ? null : (string) $mapping->getKey(),
                'mapping_status' => $mapping?->status, 'site_id' => $site?->getKey() === null ? null : (string) $site->getKey(),
                'site_status' => $site === null ? null : ($site->trashed() ? 'archived' : 'active'),
                'site_name' => $site?->name, 'site_domains' => $site?->domains, 'site_timezone' => $site?->timezone,
                'site_has_events' => $site?->events()->exists() ?? false,
            ];
        }
        $currentSites = $workspace->sites()->count();
        $limit = $plan->available && $plan->hasLimit('sites') ? $plan->limit('sites') : null;
        $projected = $currentSites + $createCount;
        if ($limit !== null && $projected > $limit) {
            $blockers[] = __('The current Analytics plan does not have room for all requested sites.');
        }
        $requirements = [];
        foreach ($configuration['sites'] as $definition) {
            $requirements[] = __('Complete DNS verification and install the Analytics tracker for :site.', ['site' => $definition['name']]);
        }
        $changes = [];
        if ($createCount > 0) {
            $changes[] = __('Create :count Analytics site(s).', ['count' => $createCount]);
        }
        if ($updateCount > 0) {
            $changes[] = __('Update :count mapped Analytics site(s).', ['count' => $updateCount]);
        }
        if ($reuseCount > 0) {
            $changes[] = __('Keep :count mapped Analytics site(s) unchanged.', ['count' => $reuseCount]);
        }
        $role = $this->access->roleFor($context['actor'], $workspace);
        $authority = [
            'actor_source_id' => (string) $this->identities->sourceIdsFor($context['actor'], 'analytics')[0],
            'workspace_source_id' => (string) $workspace->getKey(), 'role' => $role?->value,
            'plan_available' => $plan->available, 'plan_key' => $plan->planKey,
            'site_management' => $plan->allows('site_management'), 'site_limit' => $limit,
            'current_sites' => $currentSites, 'sites' => $authoritySites,
        ];

        return [
            'targets' => $targets, 'plan' => $plan, 'blockers' => array_values(array_unique($blockers)),
            'requirements' => array_values(array_unique($requirements)), 'changes' => $changes,
            'planImpact' => ['current_sites' => $currentSites, 'requested_new_sites' => $createCount,
                'projected_sites' => $projected, 'site_limit' => $limit],
            'authority' => $authority,
        ];
    }

    private function lockSourceRows(AnalyticsUser $actor, Workspace $workspace): void
    {
        foreach (['workspaces' => (string) $workspace->getKey(), 'users' => (string) $actor->getKey()] as $table => $id) {
            DB::connection('analytics')->table($table)->where('id', $id)->update(['id' => DB::raw('id')]);
            DB::connection('analytics')->table($table)->where('id', $id)->lockForUpdate()->first();
        }
    }

    private function assertCurrentNativeAccess(BlueprintTarget $target, PlatformUser $actor, AnalyticsUser $nativeActor, Workspace $workspace): void
    {
        $nativeIds = $this->identities->sourceIdsFor($actor, 'analytics');
        $workspaceIds = $this->identities->sourceIdsForCanonical('analytics', 'workspace', $target->workspaceId, 'workspace');
        if (count($nativeIds) !== 1 || (string) $nativeIds[0] !== (string) $nativeActor->getKey()
            || ! $this->access->hasAccess($actor, $workspace)
            || count($workspaceIds) !== 1 || (string) $workspaceIds[0] !== (string) $workspace->getKey()
            || ! in_array($workspace->roleFor($nativeActor->getKey()), [WorkspaceRole::Owner, WorkspaceRole::Admin], true)) {
            throw new BlueprintBlocked('authority_changed');
        }
        $plan = $this->plans->resolve($workspace);
        if (! $plan->available || ! $plan->allows('site_management') || ! $plan->hasLimit('sites')
            || ($plan->limit('sites') !== null && $workspace->sites()->count() > $plan->limit('sites'))) {
            throw new BlueprintBlocked('plan_changed');
        }
    }

    private function assertReceipt(BlueprintApplicationReceipt $receipt, BlueprintStepAttempt $attempt, array $context): void
    {
        if ($receipt->actor_source_id !== (string) $context['nativeActor']->getKey()
            || $receipt->workspace_source_id !== (string) $context['workspace']->getKey()
            || $receipt->canonical_project_id !== $attempt->target->projectId
            || ! hash_equals($receipt->payload_hash, $attempt->payloadHash)) {
            throw new BlueprintBlocked('intent_changed');
        }
        $this->fence->assertWorkspaceOpen($context['workspace']->getKey());
        $this->fence->assertAccountOpen($context['nativeActor']->getKey());
        $this->assertCurrentNativeAccess($attempt->target, $context['actor'], $context['nativeActor'], $context['workspace']);
        $result = BlueprintProductResult::fromArray($receipt->result);
        $resultEnvironments = array_map(fn (BlueprintResource $resource): ?string => $resource->environmentKey, $result->resources);
        $targetEnvironments = array_keys($attempt->target->environments);
        sort($resultEnvironments);
        sort($targetEnvironments);
        if ($resultEnvironments !== $targetEnvironments || count(array_unique($resultEnvironments)) !== count($resultEnvironments)) {
            throw new BlueprintBlocked('native_binding_changed');
        }
        foreach ($result->resources as $resource) {
            if ($resource->type !== 'site' || ! isset($attempt->target->environments[$resource->environmentKey ?? ''])) {
                throw new BlueprintBlocked('native_binding_changed');
            }
            $site = Site::query()->where('workspace_id', $context['workspace']->getKey())->find($resource->sourceId);
            if ($site === null || ! app(SitePolicy::class)->manage($context['actor'], $site)) {
                throw new BlueprintBlocked('native_binding_changed');
            }
            $this->fence->assertSiteOpen($site->getKey());
        }
    }

    private function assertEnvironmentScope(BlueprintTarget $target, array $configuration): void
    {
        $configured = array_column($configuration['sites'], 'environment');
        $targetKeys = array_keys($target->environments);
        sort($configured);
        sort($targetKeys);
        if ($configured !== $targetKeys) {
            throw new BlueprintBlocked('native_binding_changed');
        }
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^https?://#', '', $domain);

        return trim((string) strtok($domain, '/'));
    }

    private function validDomain(string $domain): bool
    {
        return (bool) preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i', $domain);
    }
}
