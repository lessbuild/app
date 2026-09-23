<?php

namespace App\Modules\Monitor\Services\Core;

use App\Core\Contracts\ProjectSetupProvider;
use App\Core\Data\Projects\ProjectSetupStep;
use App\Core\Data\Projects\ProjectSetupStepState;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectEnvironment;
use App\Core\Models\ProjectResource;
use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Models\MonitorCheck;
use App\Modules\Monitor\Models\TelemetryEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

final class MonitorProjectSetup implements ProjectSetupProvider
{
    public function __construct(private readonly MonitorProjectLink $applications) {}

    public function steps(PlatformUser $user, Project $project): array
    {
        $authorizedApplications = $this->applications->accessibleApplications($user, $project);
        $application = $authorizedApplications->first();
        $applicationUrl = $application !== null && Route::has('monitor.applications.show')
            ? route('monitor.applications.show', $application->getKey())
            : null;
        $applicationIndexUrl = Route::has('monitor.applications.index') ? route('monitor.applications.index') : null;

        if ($application === null) {
            return [new ProjectSetupStep(
                id: 'monitor.application',
                product: 'monitor',
                title: __('Connect a Monitor application'),
                detail: __('Open Monitor to create an application or confirm an imported application. Existing checks and environments can be reused.'),
                state: ProjectSetupStepState::NeedsAction,
                url: $applicationIndexUrl,
                actionLabel: __('Open Monitor'),
            )];
        }

        $environmentSteps = $this->stepsForMappedEnvironments($project, $authorizedApplications, $applicationIndexUrl);

        if ($environmentSteps !== null) {
            $applicationStep = new ProjectSetupStep(
                id: 'monitor.application',
                product: 'monitor',
                title: __('Connect a Monitor application'),
                detail: trans_choice(':count Monitor application is linked to this project.|:count Monitor applications are linked to this project.', $authorizedApplications->count(), ['count' => $authorizedApplications->count()]),
                state: ProjectSetupStepState::Complete,
                url: $applicationUrl,
                actionLabel: __('Open Monitor'),
            );

            return [$applicationStep, ...$environmentSteps];
        }

        $environmentIds = Environment::query()
            ->whereIn('application_id', $authorizedApplications->modelKeys())
            ->where('status', 'active')
            ->pluck('id');
        $hasActiveEnvironment = $environmentIds->isNotEmpty();
        $hasReceivedData = $hasActiveEnvironment && (
            TelemetryEvent::query()->whereIn('environment_id', $environmentIds)->exists()
            || MonitorCheck::query()
                ->whereNotNull('finished_at')
                ->whereIn('monitor_id', Monitor::query()
                    ->whereIn('environment_id', $environmentIds)
                    ->select('id'))
                ->exists()
        );

        return [
            new ProjectSetupStep(
                id: 'monitor.application',
                product: 'monitor',
                title: __('Connect a Monitor application'),
                detail: trans_choice(':count Monitor application is linked to this project.|:count Monitor applications are linked to this project.', $authorizedApplications->count(), ['count' => $authorizedApplications->count()]),
                state: ProjectSetupStepState::Complete,
                url: $applicationUrl,
                actionLabel: __('Open Monitor'),
            ),
            new ProjectSetupStep(
                id: 'monitor.data',
                product: 'monitor',
                title: __('Receive monitoring data'),
                detail: $hasReceivedData
                    ? __('Monitor has received telemetry or completed a health check for this project.')
                    : ($hasActiveEnvironment
                        ? __('Send telemetry or complete a health check from an active environment.')
                        : __('Add an active environment before sending telemetry or health checks.')),
                state: $hasReceivedData ? ProjectSetupStepState::Complete : ProjectSetupStepState::NeedsAction,
                url: $applicationUrl,
                actionLabel: $hasReceivedData ? null : __('Open application'),
            ),
        ];
    }

    /**
     * @param  Collection<int, Application>  $authorizedApplications
     * @return ?list<ProjectSetupStep> Null when the project has no explicit Monitor environment mappings.
     */
    private function stepsForMappedEnvironments(Project $project, Collection $authorizedApplications, ?string $applicationIndexUrl): ?array
    {
        $mappings = ProjectResource::query()
            ->where('project_id', $project->getKey())
            ->where('product', 'monitor')
            ->where('resource_type', 'environment')
            ->where('status', 'active')
            ->whereNotNull('environment_id')
            ->orderBy('id')
            ->get(['id', 'environment_id', 'resource_id', 'name']);

        if ($mappings->isEmpty()) {
            return null;
        }

        $canonicalEnvironments = ProjectEnvironment::query()
            ->where('project_id', $project->getKey())
            ->whereIn('id', $mappings->pluck('environment_id')->unique())
            ->get(['id', 'name'])
            ->keyBy(fn (ProjectEnvironment $environment): string => (string) $environment->getKey());
        $applicationsById = $authorizedApplications->keyBy(fn ($authorizedApplication): string => (string) $authorizedApplication->getKey());
        $sourceEnvironments = Environment::query()
            ->whereIn('id', $mappings->pluck('resource_id')->unique())
            ->whereIn('application_id', $authorizedApplications->modelKeys())
            ->get(['id', 'application_id', 'name', 'status'])
            ->keyBy(fn (Environment $environment): string => (string) $environment->getKey());

        return $mappings->map(function (ProjectResource $mapping) use ($canonicalEnvironments, $applicationsById, $sourceEnvironments, $applicationIndexUrl): ProjectSetupStep {
            $canonicalEnvironment = $canonicalEnvironments->get((string) $mapping->environment_id);
            $sourceEnvironment = $sourceEnvironments->get((string) $mapping->resource_id);

            if ($canonicalEnvironment === null || $sourceEnvironment === null || $sourceEnvironment->status !== 'active') {
                return new ProjectSetupStep(
                    id: 'monitor.data.'.$mapping->getKey(),
                    product: 'monitor',
                    title: __('Review monitoring environment mapping'),
                    detail: __('The linked Monitor environment is no longer available. Review its mapping before relying on setup status.'),
                    state: ProjectSetupStepState::NeedsAction,
                    url: $applicationIndexUrl,
                    actionLabel: $applicationIndexUrl === null ? null : __('Review Monitor'),
                    contextName: $canonicalEnvironment?->name ?? $mapping->name,
                    contextLabel: __('Environment'),
                );
            }

            $application = $applicationsById->get((string) $sourceEnvironment->application_id);
            $environmentUrl = $application !== null && Route::has('monitor.environments.show')
                ? route('monitor.environments.show', [$application, $sourceEnvironment])
                : $applicationIndexUrl;
            $hasReceivedData = TelemetryEvent::query()
                ->where('environment_id', $sourceEnvironment->getKey())
                ->exists()
                || MonitorCheck::query()
                    ->whereNotNull('finished_at')
                    ->whereIn('monitor_id', Monitor::query()
                        ->where('environment_id', $sourceEnvironment->getKey())
                        ->select('id'))
                    ->exists();

            return new ProjectSetupStep(
                id: 'monitor.data.'.$mapping->getKey(),
                product: 'monitor',
                title: __('Receive monitoring data'),
                detail: $hasReceivedData
                    ? __('Monitor has received telemetry or completed a health check for this environment.')
                    : __('Send telemetry or complete a health check for this environment.'),
                state: $hasReceivedData ? ProjectSetupStepState::Complete : ProjectSetupStepState::NeedsAction,
                url: $hasReceivedData ? null : $environmentUrl,
                actionLabel: $hasReceivedData || $environmentUrl === null ? null : __('Open environment'),
                contextName: $canonicalEnvironment->name,
                contextLabel: __('Environment'),
            );
        })->values()->all();
    }
}
