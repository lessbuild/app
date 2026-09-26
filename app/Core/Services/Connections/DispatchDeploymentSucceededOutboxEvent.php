<?php

namespace App\Core\Services\Connections;

use App\Core\Data\Connections\ProjectConnectionOutboxEvent;
use App\Core\Enums\ProjectConnectionCapability;
use App\Core\Models\ProjectConnection;
use App\Core\Models\ProjectConnectionDelivery;
use App\Core\Models\ProjectResource;
use RuntimeException;

final class DispatchDeploymentSucceededOutboxEvent
{
    public function dispatch(ProjectConnectionOutboxEvent $event): int
    {
        $created = 0;

        foreach ($this->deliveryCandidates($event) as [$connection, $targetPayload]) {
            $delivery = ProjectConnectionDelivery::query()->firstOrCreate(
                [
                    'source_event_id' => $event->id,
                    'project_connection_id' => $connection->getKey(),
                ],
                [
                    'event_type' => $event->eventType,
                    'event_version' => $event->eventVersion,
                    'payload' => [
                        ...$event->payload,
                        'source_project_id' => $event->sourceProjectId,
                        'source_environment_id' => $event->sourceEnvironmentId,
                        'source_build_id' => $event->sourceBuildId,
                        'canonical_project_id' => (string) $connection->sourceResource->project_id,
                        'canonical_environment_id' => (string) $connection->sourceResource->environment_id,
                        ...$targetPayload,
                    ],
                    'status' => 'pending',
                    'attempts' => 0,
                    'available_at' => now(),
                ],
            );

            if ($delivery->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    /** Count eligible connection deliveries which have not yet been recorded. */
    public function missingDeliveryCount(ProjectConnectionOutboxEvent $event): int
    {
        $candidates = $this->deliveryCandidates($event);

        if ($candidates === []) {
            return 0;
        }

        $connectionIds = collect($candidates)->map(fn (array $candidate): string => (string) $candidate[0]->getKey());
        $existingConnectionIds = ProjectConnectionDelivery::query()
            ->where('source_event_id', $event->id)
            ->whereIn('project_connection_id', $connectionIds)
            ->pluck('project_connection_id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        return $connectionIds->diff($existingConnectionIds)->count();
    }

    /** @return list<array{0: ProjectConnection, 1: array{target_environment_id: string}|array{target_site_id: string}}> */
    private function deliveryCandidates(ProjectConnectionOutboxEvent $event): array
    {
        $payload = $event->payload;

        if ($event->sourceProduct !== 'deployer'
            || $event->eventType !== ProjectConnectionOutboxEvent::DEPLOYER_DEPLOYMENT_SUCCEEDED
            || $event->eventVersion !== 1) {
            throw new RuntimeException('The Deployer event type or version is unsupported.');
        }

        if ($event->sourceEnvironmentId === null
            || $event->sourceProjectId === null
            || $event->sourceBuildId === null
            || ! is_string($payload['deployment_id'] ?? null)
            || ! is_string($payload['version'] ?? null)
            || ! is_string($payload['deployed_at'] ?? null)) {
            throw new RuntimeException('The Deployer deployment event payload is invalid.');
        }

        $sourceEnvironment = ProjectResource::query()
            ->where('product', 'deployer')
            ->where('resource_type', 'environment')
            ->where('resource_id', $event->sourceEnvironmentId)
            ->where('status', 'active')
            ->first();

        if ($sourceEnvironment === null || $sourceEnvironment->environment_id === null) {
            return [];
        }

        $sourceProject = ProjectResource::query()
            ->where('product', 'deployer')
            ->where('resource_type', 'project')
            ->where('resource_id', $event->sourceProjectId)
            ->where('status', 'active')
            ->first();

        if ($sourceProject === null) {
            return [];
        }

        if ($sourceProject->project_id !== $sourceEnvironment->project_id) {
            throw new RuntimeException('The Deployer project mapping does not match its environment.');
        }

        $connections = ProjectConnection::query()
            ->where('project_id', $sourceEnvironment->project_id)
            ->where('created_at', '<=', $event->createdAt)
            ->whereIn('status', ['pending', 'active', 'failed'])
            ->whereNull('disconnected_at')
            ->with(['sourceResource', 'targetResource'])
            ->get();
        $candidates = [];

        foreach ($connections as $connection) {
            $source = $connection->sourceResource;
            $target = $connection->targetResource;

            if ($source?->getKey() !== $sourceEnvironment->getKey()
                || $connection->source_environment_id !== $sourceEnvironment->environment_id
                || $target === null
                || $target->status !== 'active') {
                continue;
            }

            $connectionCapabilities = (array) $connection->capabilities;
            $targetPayload = null;

            if (in_array(ProjectConnectionCapability::DeploymentContext->value, $connectionCapabilities, true)
                && $target->product === 'monitor'
                && $target->resource_type === 'environment'
                && $target->environment_id !== null
                && $connection->target_environment_id === $target->environment_id) {
                $targetPayload = ['target_environment_id' => (string) $target->resource_id];
            } elseif (in_array(ProjectConnectionCapability::ReleaseAnnotations->value, $connectionCapabilities, true)
                && $target->product === 'analytics'
                && $target->resource_type === 'site'
                && $target->environment_id === null
                && $connection->target_environment_id === null) {
                $targetPayload = ['target_site_id' => (string) $target->resource_id];
            }

            if ($targetPayload !== null) {
                $candidates[] = [$connection, $targetPayload];
            }
        }

        return $candidates;
    }
}
