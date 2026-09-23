<?php

namespace App\Core\Services\Projects;

use App\Core\Data\Projects\ProjectWorkflowRun;
use App\Core\Data\Projects\ProjectWorkflowStep;
use App\Core\Enums\ProjectWorkflowStepState;
use App\Core\Models\Project;
use App\Core\Models\ProjectConnection;
use App\Core\Models\ProjectConnectionDelivery;
use App\Core\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Throwable;

final class ProjectWorkflowProgress
{
    private const RECENT_RUNS = 12;

    /**
     * @param  Collection<int, ProjectConnection>  $connections  Connections whose endpoints were already authorized.
     * @return Collection<int, ProjectWorkflowRun>
     */
    public function forProject(
        Workspace $workspace,
        Project $project,
        Collection $connections,
        bool $canManageConnections,
    ): Collection {
        $connectionIds = $connections->modelKeys();

        if ($connectionIds === []) {
            return collect();
        }

        $recentEvents = ProjectConnectionDelivery::query()
            ->whereIn('project_connection_id', $connectionIds)
            ->select(['source_event_id', 'event_type'])
            ->selectRaw('MAX(created_at) AS latest_created_at')
            ->groupBy('source_event_id', 'event_type')
            ->orderByDesc('latest_created_at')
            ->limit(self::RECENT_RUNS)
            ->get();

        if ($recentEvents->isEmpty()) {
            return collect();
        }

        $eventIds = $recentEvents->pluck('source_event_id')->unique()->values();
        $eventTypes = $recentEvents->pluck('event_type')->unique()->values();
        $selectedKeys = $recentEvents->mapWithKeys(fn (ProjectConnectionDelivery $event): array => [
            $this->eventKey((string) $event->event_type, (string) $event->source_event_id) => true,
        ]);
        $connectionsById = $connections->keyBy(fn (ProjectConnection $connection): string => (string) $connection->getKey());
        $deliveriesByEvent = ProjectConnectionDelivery::query()
            ->whereIn('project_connection_id', $connectionIds)
            ->whereIn('source_event_id', $eventIds)
            ->whereIn('event_type', $eventTypes)
            ->orderBy('created_at')
            ->get()
            ->filter(fn (ProjectConnectionDelivery $delivery): bool => $selectedKeys->has($this->eventKey($delivery->event_type, $delivery->source_event_id)))
            ->groupBy(fn (ProjectConnectionDelivery $delivery): string => $this->eventKey($delivery->event_type, $delivery->source_event_id));

        return $recentEvents
            ->map(function (ProjectConnectionDelivery $event) use ($deliveriesByEvent, $connectionsById, $workspace, $project, $canManageConnections): ?ProjectWorkflowRun {
                $eventKey = $this->eventKey((string) $event->event_type, (string) $event->source_event_id);
                $deliveries = $deliveriesByEvent->get($eventKey, collect());
                $eligibleDeliveries = $deliveries->filter(fn (ProjectConnectionDelivery $delivery): bool => $connectionsById->has((string) $delivery->project_connection_id));

                if ($eligibleDeliveries->isEmpty()) {
                    return null;
                }

                $firstDelivery = $eligibleDeliveries->first();
                $recordedAt = $this->sourceTimestamp($firstDelivery);
                $sourceTitle = $this->sourceTitle($firstDelivery);
                $steps = [new ProjectWorkflowStep(
                    product: $this->sourceProduct($firstDelivery->event_type),
                    productLabel: $this->productLabel($this->sourceProduct($firstDelivery->event_type)),
                    title: $sourceTitle,
                    detail: $firstDelivery->event_type === 'deployer.deployment_succeeded'
                        ? __('The deployment succeeded. Its result is retained independently if a connected app has a delivery problem.')
                        : __('The Monitor incident update is recorded independently if a connected app has a delivery problem.'),
                    state: ProjectWorkflowStepState::Succeeded,
                    recordedAt: $recordedAt,
                    completedAt: $recordedAt,
                )];

                foreach ($eligibleDeliveries as $delivery) {
                    $connection = $connectionsById->get((string) $delivery->project_connection_id);
                    $target = $connection?->targetResource;
                    $state = ProjectWorkflowStepState::tryFrom($delivery->status) ?? ProjectWorkflowStepState::Failed;
                    $isRetryable = in_array($delivery->status, ['failed', 'blocked'], true)
                        && $connection?->automation_paused_at === null
                        && $canManageConnections
                        && Route::has('core.projects.connections.deliveries.retry');

                    $steps[] = new ProjectWorkflowStep(
                        product: $target?->product ?? 'core',
                        productLabel: $this->productLabel($target?->product ?? 'core'),
                        title: $this->targetTitle($delivery, $target?->product),
                        detail: $this->targetDetail($delivery, $connection?->automation_paused_at !== null),
                        state: $state,
                        recordedAt: $delivery->created_at?->toImmutable() ?? $recordedAt,
                        attemptedAt: $delivery->last_attempted_at?->toImmutable(),
                        completedAt: $delivery->delivered_at?->toImmutable(),
                        connectionId: (string) $delivery->project_connection_id,
                        deliveryId: (string) $delivery->getKey(),
                        retryUrl: $isRetryable
                            ? route('core.projects.connections.deliveries.retry', [$workspace, $project, $connection, $delivery])
                            : null,
                    );
                }

                return new ProjectWorkflowRun(
                    key: $eventKey,
                    title: $sourceTitle,
                    recordedAt: $recordedAt,
                    steps: $steps,
                );
            })
            ->filter()
            ->values();
    }

    private function eventKey(string $eventType, string $sourceEventId): string
    {
        return $eventType.':'.$sourceEventId;
    }

    private function sourceProduct(string $eventType): string
    {
        return str_starts_with($eventType, 'monitor.') ? 'monitor' : 'deployer';
    }

    private function sourceTitle(ProjectConnectionDelivery $delivery): string
    {
        return match ($delivery->event_type) {
            'deployer.deployment_succeeded' => filled(data_get($delivery->payload, 'version'))
                ? __('Deployment :version succeeded', ['version' => str(data_get($delivery->payload, 'version'))->limit(48)])
                : __('Deployment succeeded'),
            'monitor.incident_opened' => __('Monitor incident opened'),
            'monitor.incident_acknowledged' => __('Monitor incident acknowledged'),
            'monitor.incident_resolved' => __('Monitor incident resolved'),
            default => __('Product event recorded'),
        };
    }

    private function targetTitle(ProjectConnectionDelivery $delivery, ?string $targetProduct): string
    {
        return match ([$delivery->event_type, $targetProduct]) {
            ['deployer.deployment_succeeded', 'monitor'] => __('Deployment context recorded'),
            ['deployer.deployment_succeeded', 'analytics'] => __('Release annotation added'),
            default => __('Incident update added'),
        };
    }

    private function targetDetail(ProjectConnectionDelivery $delivery, bool $paused): string
    {
        if ($delivery->last_error_code === 'automation_paused') {
            return __('Automation was paused before this step completed. Resume it before retrying this step.');
        }

        return match ($delivery->status) {
            'pending' => $paused
                ? __('This update is queued and will continue when automation resumes.')
                : __('Waiting for the receiving application to process this update.'),
            'processing' => __('The receiving application is applying this update.'),
            'delivered' => __('The receiving application recorded this update.'),
            'blocked' => __('Current access or plan checks stopped this step. Review the connection before retrying it.'),
            'failed' => __('The receiving application could not complete this step. Review the connection before retrying it.'),
            'discarded' => __('The connection was disconnected before this step could be delivered. Source history remains available.'),
            default => __('The delivery status is unavailable.'),
        };
    }

    private function productLabel(string $product): string
    {
        return (string) config('platform.products.'.$product.'.label', str($product)->headline());
    }

    private function sourceTimestamp(ProjectConnectionDelivery $delivery): CarbonImmutable
    {
        $payloadKey = $delivery->event_type === 'deployer.deployment_succeeded' ? 'deployed_at' : 'occurred_at';
        $timestamp = data_get($delivery->payload, $payloadKey);

        if (is_string($timestamp) && $timestamp !== '') {
            try {
                return CarbonImmutable::parse($timestamp)->utc();
            } catch (Throwable) {
                // Fall back to the Core receipt time for malformed historical payloads.
            }
        }

        return $delivery->created_at?->toImmutable() ?? CarbonImmutable::now('UTC');
    }
}
