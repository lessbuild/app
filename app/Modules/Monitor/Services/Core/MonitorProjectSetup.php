<?php

namespace App\Modules\Monitor\Services\Core;

use App\Core\Contracts\ProjectSetupProvider;
use App\Core\Data\Projects\ProjectSetupStep;
use App\Core\Data\Projects\ProjectSetupStepState;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Models\MonitorCheck;
use App\Modules\Monitor\Models\TelemetryEvent;
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
}
