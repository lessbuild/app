<?php

namespace App\Core\Services;

use App\Core\Data\Projects\ProjectReleaseTrafficContextSnapshot;
use App\Core\Data\Projects\ProjectTrafficContextSnapshot;
use App\Core\Enums\ProjectConnectionCapability;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectConnection;
use App\Core\Models\ProjectResource;
use App\Core\Services\Connections\ProjectConnectionEntitlementPolicy;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

final class ResolveProjectTrafficContext
{
    public function __construct(
        private readonly WorkspaceProjectAccess $access,
        private readonly ProjectConnectionEntitlementPolicy $entitlements,
        private readonly ProjectTrafficContextRegistry $providers,
    ) {}

    /** @return Collection<int, ProjectTrafficContextSnapshot> */
    public function forMonitorEnvironment(
        PlatformUser $user,
        string|int $monitorEnvironmentId,
        DateTimeInterface $incidentOpenedAt,
    ): Collection {
        $openedAt = CarbonImmutable::instance($incidentOpenedAt)->utc();
        $contexts = collect();
        $targets = ProjectResource::query()
            ->where('product', 'monitor')
            ->where('resource_type', 'environment')
            ->where('resource_id', (string) $monitorEnvironmentId)
            ->where('status', 'active')
            ->get();

        foreach ($targets as $target) {
            $project = Project::query()->find($target->project_id);

            if ($project === null
                || ! $this->access->canViewProject($user, $project)
                || ! $this->access->canAccessProductResource($user, $project, 'monitor')
                || ! $this->access->canAccessProductResource($user, $project, 'analytics')) {
                continue;
            }

            $provider = $this->providers->get('analytics');

            if ($provider === null) {
                continue;
            }

            $windowMinutes = $this->entitlements->trafficContextWindowMinutes($project);

            if ($windowMinutes === null) {
                continue;
            }

            $connections = ProjectConnection::query()
                ->where('project_id', $project->getKey())
                ->where('target_resource_id', $target->getKey())
                ->where('status', 'active')
                ->whereNull('disconnected_at')
                ->with('sourceResource')
                ->orderBy('id')
                ->get();

            foreach ($connections as $connection) {
                if (! in_array(ProjectConnectionCapability::TrafficContext->value, $connection->capabilities ?? [], true)) {
                    continue;
                }

                $source = $connection->sourceResource;

                if ($source === null
                    || $source->product !== 'analytics'
                    || $source->resource_type !== 'site'
                    || $source->status !== 'active') {
                    continue;
                }

                $incidentWindowStart = $openedAt->subMinutes($windowMinutes);
                $previousWindowStart = $openedAt->subMinutes($windowMinutes * 2);
                $incidentWindow = $provider->aggregate($user, $project, $source, $incidentWindowStart, $openedAt);
                $previousWindow = $provider->aggregate($user, $project, $source, $previousWindowStart, $incidentWindowStart);

                if ($incidentWindow === null || $previousWindow === null) {
                    continue;
                }

                $contexts->push(new ProjectTrafficContextSnapshot(
                    projectName: $project->name,
                    siteName: $source->name ?: __('Analytics site'),
                    windowMinutes: $windowMinutes,
                    incidentOpenedAt: $openedAt,
                    incidentWindow: $incidentWindow,
                    previousWindow: $previousWindow,
                ));
            }
        }

        return $contexts;
    }

    /** @return Collection<int, ProjectReleaseTrafficContextSnapshot> */
    public function forMonitorDeployment(
        PlatformUser $user,
        string|int $monitorEnvironmentId,
        DateTimeInterface $deployedAt,
        int $requestedSeconds,
    ): Collection {
        if ($requestedSeconds <= 0) {
            return collect();
        }

        $at = CarbonImmutable::instance($deployedAt)->utc();
        $elapsedSeconds = (int) max(0, $at->diffInSeconds(CarbonImmutable::now('UTC'), false));
        $contexts = collect();
        $targets = ProjectResource::query()
            ->where('product', 'monitor')
            ->where('resource_type', 'environment')
            ->where('resource_id', (string) $monitorEnvironmentId)
            ->where('status', 'active')
            ->get();

        foreach ($targets as $target) {
            $project = Project::query()->find($target->project_id);

            if ($project === null
                || ! $this->access->canViewProject($user, $project)
                || ! $this->access->canAccessProductResource($user, $project, 'monitor')
                || ! $this->access->canAccessProductResource($user, $project, 'analytics')) {
                continue;
            }

            $provider = $this->providers->get('analytics');
            $maximumMinutes = $this->entitlements->trafficContextWindowMinutes($project);

            if ($provider === null || $maximumMinutes === null) {
                continue;
            }

            $windowSeconds = min($requestedSeconds, $maximumMinutes * 60, $elapsedSeconds);

            if ($windowSeconds <= 0) {
                continue;
            }

            $connections = ProjectConnection::query()
                ->where('project_id', $project->getKey())
                ->where('target_resource_id', $target->getKey())
                ->where('status', 'active')
                ->whereNull('disconnected_at')
                ->with('sourceResource')
                ->orderBy('id')
                ->get();

            foreach ($connections as $connection) {
                if (! in_array(ProjectConnectionCapability::TrafficContext->value, $connection->capabilities ?? [], true)) {
                    continue;
                }

                $source = $connection->sourceResource;

                if ($source === null
                    || $source->product !== 'analytics'
                    || $source->resource_type !== 'site'
                    || $source->status !== 'active') {
                    continue;
                }

                $before = $provider->aggregate($user, $project, $source, $at->subSeconds($windowSeconds), $at);
                $after = $provider->aggregate($user, $project, $source, $at, $at->addSeconds($windowSeconds));

                if ($before === null || $after === null) {
                    continue;
                }

                $contexts->push(new ProjectReleaseTrafficContextSnapshot(
                    projectName: $project->name,
                    siteName: $source->name ?: __('Analytics site'),
                    windowSeconds: $windowSeconds,
                    deployedAt: $at,
                    before: $before,
                    after: $after,
                ));
            }
        }

        return $contexts;
    }
}
