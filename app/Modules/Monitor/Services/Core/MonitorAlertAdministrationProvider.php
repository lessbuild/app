<?php

namespace App\Modules\Monitor\Services\Core;

use App\Core\Contracts\WorkspaceMonitorAlertAdministrationProvider;
use App\Core\Data\Monitor\MonitorAdministrationSnapshot;
use App\Core\Data\Monitor\MonitorMutationResult;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Core\Services\Identity\ProductWorkspaceAccess;
use App\Core\Services\LegacyIdentityResolver;
use App\Core\Services\WorkspaceProjectAccess;
use App\Modules\Monitor\Data\Telemetry\AlertMetric;
use App\Modules\Monitor\Models\AlertDestination;
use App\Modules\Monitor\Models\AlertRule;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\MetricSeries;
use App\Modules\Monitor\Models\ServiceLevelObjective;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use App\Modules\Monitor\Services\ChangeAlertEscalations;
use App\Modules\Monitor\Services\ChangeAlertRouting;
use App\Modules\Monitor\Services\ChangeAlertRule;
use App\Modules\Monitor\Services\MonitorPlanAuthority;
use App\Modules\Monitor\Services\Telemetry\TelemetryRedactor;
use App\Modules\Monitor\Services\WorkspacePlanLimits;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class MonitorAlertAdministrationProvider implements WorkspaceMonitorAlertAdministrationProvider
{
    private readonly MonitorAdministrationContext $context;

    public function __construct(
        LegacyIdentityResolver $identities,
        WorkspaceProjectAccess $workspaceAccess,
        ProductWorkspaceAccess $productAccess,
        private readonly TelemetryRedactor $redactor,
        private readonly WorkspacePlanLimits $limits,
        private readonly ChangeAlertRule $rules,
        private readonly ChangeAlertRouting $routing,
        private readonly ChangeAlertEscalations $escalations,
    ) {
        $this->context = new MonitorAdministrationContext($identities, $workspaceAccess, $productAccess);
    }

    public function snapshot(PlatformUser $user, CoreWorkspace $workspace, array $filters = []): MonitorAdministrationSnapshot
    {
        $this->context->resetReferences();
        $resolved = $this->context->resolve($user, $workspace);
        if ($resolved === null) {
            return new MonitorAdministrationSnapshot(collect(), available: false);
        }
        ['workspace' => $sourceWorkspace, 'user' => $actor] = $resolved;

        $environments = Environment::forWorkspace($sourceWorkspace)->visibleTo($actor, $sourceWorkspace)->with('application:id,name')
            ->when(filled($filters['environment_search'] ?? null), fn ($query) => $query->where(function ($query) use ($filters): void {
                $search = '%'.trim((string) $filters['environment_search']).'%';
                $query->where('name', 'like', $search)->orWhere('slug', 'like', $search)
                    ->orWhereHas('application', fn ($application) => $application->where('name', 'like', $search));
            }))->orderBy('application_id')->orderBy('name')->orderBy('id')
            ->paginate(25, ['id', 'application_id', 'name', 'status'], 'environment_page')->withQueryString();
        $canManageDestinations = Gate::forUser($actor)->allows('update', $sourceWorkspace);
        $destinations = AlertDestination::forWorkspace($sourceWorkspace)
            ->when(! $canManageDestinations, fn ($query) => $query->whereRaw('1 = 0'))
            ->when(filled($filters['destination_search'] ?? null), fn ($query) => $query->where('name', 'like', '%'.trim((string) $filters['destination_search']).'%'))
            ->orderBy('name')->orderBy('id')->paginate(25, ['id', 'name', 'enabled'], 'destination_page')->withQueryString();
        $rules = AlertRule::withTrashed()->forWorkspace($sourceWorkspace)->visibleTo($actor, $sourceWorkspace)
            ->when(filled($filters['rules_search'] ?? null), fn ($query) => $query->where(function ($query) use ($filters): void {
                $search = '%'.trim((string) $filters['rules_search']).'%';
                $query->where('name', 'like', $search)->orWhere('metric', 'like', $search)
                    ->orWhere('service', 'like', $search)->orWhere('match_text', 'like', $search);
            }))->with(['environment.application', 'metricSeries', 'serviceLevelObjective', 'destinations', 'escalations.destination'])
            ->latest('created_at')->latest('id')->paginate(20, ['*'], 'rules_page')->withQueryString();
        // Keep every current selection represented even when it falls outside a bounded picker.
        // Otherwise saving a routing form could silently remove an existing destination.
        $ruleModels = $rules->getCollection();
        $ruleIds = $ruleModels->modelKeys();
        $routeDestinationIds = DB::connection('monitor')->table('alert_destination_alert_rule')
            ->whereIn('alert_rule_id', $ruleIds)->get(['alert_rule_id', 'alert_destination_id'])
            ->groupBy('alert_rule_id')->map(fn ($routes) => $routes->pluck('alert_destination_id')->map(fn ($id) => (int) $id)->all());
        $selectedDestinationIds = $routeDestinationIds->flatten()
            ->merge($ruleModels->flatMap(fn (AlertRule $rule) => $rule->escalations->pluck('destination_id')))
            ->filter()->unique()->values();
        $selectedDestinations = AlertDestination::withTrashed()->forWorkspace($sourceWorkspace)->whereKey($selectedDestinationIds)->get(['id', 'name', 'enabled', 'deleted_at'])
            ->filter(fn (AlertDestination $destination): bool => Gate::forUser($actor)->allows('view', $destination));
        $selectedDestinationById = $selectedDestinations->keyBy(fn (AlertDestination $destination): int => (int) $destination->getKey());
        $destinations->setCollection($destinations->getCollection()->merge($selectedDestinations)->unique('id')->sortBy('name')->values());
        $selectedEnvironments = Environment::withTrashed()->forWorkspace($sourceWorkspace)->visibleTo($actor, $sourceWorkspace)
            ->with('application:id,name')->whereKey($ruleModels->pluck('environment_id')->unique())->get();
        $selectedEnvironmentById = $selectedEnvironments->keyBy(fn (Environment $environment): int => (int) $environment->getKey());
        $environments->setCollection($environments->getCollection()->merge($selectedEnvironments)->unique('id')->values());
        $items = $ruleModels->map(function (AlertRule $rule) use ($sourceWorkspace, $actor, $routeDestinationIds, $selectedDestinationById, $selectedEnvironmentById): array {
            $canUpdate = Gate::forUser($actor)->allows('update', $rule);
            $rule = $rule->forceFill($this->redactor->redact($rule->only(['name', 'service', 'match_text'])));
            $snapshot = $rule->snapshot();
            unset($snapshot['metric_series_id'], $snapshot['service_level_objective_id']);

            return [
                'kind' => 'rule',
                'reference' => $this->context->reference('rule', $rule->getKey(), $sourceWorkspace),
                'name' => $snapshot['name'] ?? '',
                'metric' => $rule->metric->value,
                'metric_label' => $rule->metric->label(),
                'enabled' => (bool) $rule->enabled,
                'archived' => $rule->trashed(),
                'version' => (int) $rule->state_version,
                'environment' => $rule->environment?->application?->name.' / '.$rule->environment?->name,
                'conditions' => $snapshot,
                'environment_reference' => $selectedEnvironmentById->has((int) $rule->environment_id)
                    ? $this->context->reference('environment', $rule->environment_id, $sourceWorkspace) : null,
                'metric_series_reference' => $rule->metric_series_id === null ? null : $this->context->reference('metric-series', $rule->metric_series_id, $sourceWorkspace),
                'objective_reference' => $rule->service_level_objective_id === null ? null : $this->context->reference('objective', $rule->service_level_objective_id, $sourceWorkspace),
                'destinations' => collect($routeDestinationIds->get($rule->getKey(), []))->filter(fn (int $id): bool => $selectedDestinationById->has($id))
                    ->map(fn (int $id): string => $this->context->reference('destination', $id, $sourceWorkspace))->values()->all(),
                'opened' => $rule->destinations->contains(fn (AlertDestination $destination): bool => (bool) $destination->pivot?->opened),
                'recovered' => $rule->destinations->contains(fn (AlertDestination $destination): bool => (bool) $destination->pivot?->recovered),
                'escalations' => $rule->escalations->map(fn ($step): array => [
                    'destination_reference' => $step->destination_id === null || ! $selectedDestinationById->has((int) $step->destination_id)
                        ? null : $this->context->reference('destination', $step->destination_id, $sourceWorkspace),
                    'destination_name' => $selectedDestinationById->get((int) $step->destination_id)?->name,
                    'delay_minutes' => (int) $step->delay_minutes,
                ])->all(),
                'can_update' => $canUpdate,
            ];
        })->values();
        $rules->setCollection($items);

        $metricSeries = MetricSeries::forWorkspace($sourceWorkspace)->visibleTo($actor, $sourceWorkspace)
            ->when(filled($filters['series_search'] ?? null), fn ($query) => $query->where(function ($query) use ($filters): void {
                $search = '%'.trim((string) $filters['series_search']).'%';
                $query->where('name', 'like', $search)->orWhere('resource_label', 'like', $search)
                    ->orWhereHas('environment', fn ($environment) => $environment->where('name', 'like', $search)
                        ->orWhereHas('application', fn ($application) => $application->where('name', 'like', $search)));
            }))->with('environment.application')->orderBy('environment_id')->orderBy('name')
            ->paginate(25, ['*'], 'series_page')->withQueryString();
        $selectedMetricSeries = MetricSeries::forWorkspace($sourceWorkspace)->visibleTo($actor, $sourceWorkspace)
            ->with('environment.application')->whereKey($ruleModels->pluck('metric_series_id')->filter()->unique())->get();
        $metricSeries->setCollection($metricSeries->getCollection()->merge($selectedMetricSeries)->unique('id')->values());
        $objectives = ServiceLevelObjective::forWorkspace($sourceWorkspace)->visibleTo($actor, $sourceWorkspace)
            ->where('enabled', true)->when(filled($filters['objective_search'] ?? null), fn ($query) => $query->where(function ($query) use ($filters): void {
                $search = '%'.trim((string) $filters['objective_search']).'%';
                $query->where('name', 'like', $search)->orWhere('service', 'like', $search)->orWhere('route', 'like', $search)
                    ->orWhereHas('environment', fn ($environment) => $environment->where('name', 'like', $search)
                        ->orWhereHas('application', fn ($application) => $application->where('name', 'like', $search)));
            }))->with('environment.application')->orderBy('environment_id')->orderBy('name')
            ->paginate(25, ['*'], 'objective_page')->withQueryString();
        $selectedObjectives = ServiceLevelObjective::forWorkspace($sourceWorkspace)->visibleTo($actor, $sourceWorkspace)
            ->with('environment.application')->whereKey($ruleModels->pluck('service_level_objective_id')->filter()->unique())->get();
        $objectives->setCollection($objectives->getCollection()->merge($selectedObjectives)->unique('id')->values());

        return new MonitorAdministrationSnapshot($rules, [
            'can_create' => Gate::forUser($actor)->allows('create', [AlertRule::class, $sourceWorkspace]),
            'environments' => $environments->through(fn (Environment $environment): array => [
                'reference' => $this->context->reference('environment', $environment->getKey(), $sourceWorkspace),
                'label' => $environment->application->name.' / '.$environment->name,
                'archived' => $environment->trashed(),
            ]),
            'destinations' => $destinations->through(fn (AlertDestination $destination): array => [
                'reference' => $this->context->reference('destination', $destination->getKey(), $sourceWorkspace),
                'name' => $destination->name, 'enabled' => (bool) $destination->enabled, 'archived' => $destination->trashed(),
            ]),
            'metrics' => collect(AlertMetric::cases())->map(fn (AlertMetric $metric): array => ['value' => $metric->value, 'label' => $metric->label()])->all(),
            'metric_series' => $metricSeries->through(fn (MetricSeries $series): array => [
                'reference' => $this->context->reference('metric-series', $series->getKey(), $sourceWorkspace),
                'label' => $series->environment->application->name.' / '.$series->environment->name.' · '.$series->name.' · '.$series->resource_label,
            ]),
            'objectives' => $objectives->through(fn (ServiceLevelObjective $objective): array => [
                'reference' => $this->context->reference('objective', $objective->getKey(), $sourceWorkspace),
                'label' => $objective->environment->application->name.' / '.$objective->environment->name.' · '.$objective->name.' · '.$objective->scopeLabel(),
            ]),
            'escalation_step_limit' => $this->limits->escalationStepLimit($sourceWorkspace),
        ]);
    }

    public function saveRule(PlatformUser $user, CoreWorkspace $workspace, ?string $ruleReference, array $data): MonitorMutationResult
    {
        ['workspace' => $sourceWorkspace, 'user' => $actor] = $this->requiredContext($user, $workspace);
        if ($ruleReference !== null) {
            $id = $this->context->sourceId($ruleReference, 'rule', $sourceWorkspace);
            $rule = AlertRule::forWorkspace($sourceWorkspace)->visibleTo($actor, $sourceWorkspace)->findOrFail($id);
        } else {
            Gate::forUser($actor)->authorize('create', [AlertRule::class, $sourceWorkspace]);
            $rule = null;
        }

        foreach ([['environment_reference', 'environment_id', 'environment'], ['metric_series_reference', 'metric_series_id', 'metric-series'], ['objective_reference', 'service_level_objective_id', 'objective']] as [$input, $native, $kind]) {
            if (filled($data[$input] ?? null)) {
                $data[$native] = $this->context->sourceId((string) $data[$input], $kind, $sourceWorkspace);
            }
            unset($data[$input]);
        }
        $metric = AlertMetric::tryFrom((string) ($data['metric'] ?? ''));
        abort_unless($metric instanceof AlertMetric, 422);
        if (filled($data['service'] ?? null)
            && $this->redactor->redact(['service' => $data['service']])['service'] !== $data['service']) {
            throw ValidationException::withMessages(['service' => 'Use a service label without secrets.']);
        }
        if ($metric === AlertMetric::LogPatternCount
            && $this->redactor->redact(['match_text' => $data['match_text'] ?? null])['match_text'] !== ($data['match_text'] ?? null)) {
            throw ValidationException::withMessages(['match_text' => 'Use a log pattern without secrets or sensitive values.']);
        }
        $saved = $this->context->mutate($user, $workspace, $sourceWorkspace, $actor, function (Workspace $locked) use ($actor, $data, $rule, $metric): AlertRule {
            $limits = new WorkspacePlanLimits(app(MonitorPlanAuthority::class));
            abort_unless(! $metric->isTelemetryGuardrail() || $limits->telemetryGuardrailsEnabled($locked), 403);
            abort_unless(! $metric->isSloBurnRate() || $limits->sloBurnRateAlertsEnabled($locked), 403);
            abort_unless(! $metric->isAnomaly() || $limits->anomalyDetectionEnabled($locked), 403);
            abort_unless($metric !== AlertMetric::LogPatternCount || $limits->logPatternAlertsEnabled($locked), 403);
            $minimum = match ($metric) {
                AlertMetric::NumericMetric => 'min:-1000000000000000',
                AlertMetric::TelemetryVolume => 'min:0',
                AlertMetric::MetricAnomaly => 'between:3,20',
                AlertMetric::LogPatternCount => 'min:1',
                default => 'gt:0',
            };
            Validator::make($data, ['threshold' => ['required', 'numeric', 'decimal:0,3', $minimum,
                'max:'.$metric->maximum(), ...($metric->isCount() ? ['integer'] : [])]])->validate();

            return $this->rules->save($locked, $actor, $data, $rule);
        });

        return new MonitorMutationResult(true, 'Alert rule saved.', $this->context->reference('rule', $saved->getKey(), $sourceWorkspace));
    }

    public function archiveRule(PlatformUser $user, CoreWorkspace $workspace, string $ruleReference, int $version): MonitorMutationResult
    {
        ['workspace' => $sourceWorkspace, 'user' => $actor] = $this->requiredContext($user, $workspace);
        $id = $this->context->sourceId($ruleReference, 'rule', $sourceWorkspace);
        $rule = AlertRule::forWorkspace($sourceWorkspace)->visibleTo($actor, $sourceWorkspace)->findOrFail($id);
        $this->context->mutate($user, $workspace, $sourceWorkspace, $actor, function (Workspace $locked) use ($rule, $actor, $version): void {
            $this->rules->archive($rule, $locked, $actor, $version);
        });

        return new MonitorMutationResult(true, 'Alert rule archived.');
    }

    public function saveRouting(PlatformUser $user, CoreWorkspace $workspace, string $ruleReference, array $data): MonitorMutationResult
    {
        ['workspace' => $sourceWorkspace, 'user' => $actor] = $this->requiredContext($user, $workspace);
        $rule = $this->rule($ruleReference, $sourceWorkspace, $actor);
        $data['destinations'] = collect($data['destinations'] ?? [])->map(fn (string $reference): int => (int) $this->context->sourceId($reference, 'destination', $sourceWorkspace))->all();
        $this->context->mutate($user, $workspace, $sourceWorkspace, $actor, function (Workspace $locked) use ($actor, $rule, $data): void {
            $this->routing->update($locked, $actor, $rule, $data);
        });

        return new MonitorMutationResult(true, 'Alert routing saved.');
    }

    public function saveEscalations(PlatformUser $user, CoreWorkspace $workspace, string $ruleReference, array $data): MonitorMutationResult
    {
        ['workspace' => $sourceWorkspace, 'user' => $actor] = $this->requiredContext($user, $workspace);
        $rule = $this->rule($ruleReference, $sourceWorkspace, $actor);
        $data['escalations'] = collect($data['escalations'] ?? [])->map(fn (array $step): array => [
            'destination_id' => filled($step['destination_reference'] ?? null)
                ? $this->context->sourceId((string) $step['destination_reference'], 'destination', $sourceWorkspace) : null,
            'delay_minutes' => $step['delay_minutes'] ?? null,
        ])->all();
        $this->context->mutate($user, $workspace, $sourceWorkspace, $actor, function (Workspace $locked) use ($actor, $rule, $data): void {
            $this->escalations->update($locked, $actor, $rule, $data);
        });

        return new MonitorMutationResult(true, 'Escalations saved.');
    }

    /** @return array{workspace: Workspace, user: User} */
    private function requiredContext(PlatformUser $user, CoreWorkspace $workspace): array
    {
        $resolved = $this->context->resolve($user, $workspace);
        abort_unless($resolved !== null, 404);
        Gate::forUser($resolved['user'])->authorize('update', $resolved['workspace']);

        return $resolved;
    }

    private function rule(string $reference, Workspace $workspace, User $actor): AlertRule
    {
        $id = $this->context->sourceId($reference, 'rule', $workspace);

        return AlertRule::forWorkspace($workspace)->visibleTo($actor, $workspace)->findOrFail($id);
    }
}
