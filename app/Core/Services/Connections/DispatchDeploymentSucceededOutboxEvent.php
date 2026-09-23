<?php

namespace App\Core\Services\Connections;

use App\Core\Enums\ProjectConnectionCapability;
use App\Core\Models\ProjectConnection;
use App\Core\Models\ProjectConnectionDelivery;
use App\Core\Models\ProjectResource;
use App\Modules\Deployer\Models\DeploymentSucceededOutboxEvent;
use RuntimeException;

final class DispatchDeploymentSucceededOutboxEvent
{
    public function dispatch(DeploymentSucceededOutboxEvent $event): int
    {
        if ($event->event_type !== DeploymentSucceededOutboxEvent::EVENT_TYPE || $event->event_version !== 1) {
            throw new RuntimeException('The Deployer event type or version is unsupported.');
        }

        $payload = $event->payload;
        if (! is_array($payload)
            || ! is_string($payload['deployment_id'] ?? null)
            || ! is_string($payload['version'] ?? null)
            || ! is_string($payload['deployed_at'] ?? null)) {
            throw new RuntimeException('The Deployer deployment event payload is invalid.');
        }

        $sourceEnvironment = ProjectResource::query()
            ->where('product', 'deployer')
            ->where('resource_type', 'environment')
            ->where('resource_id', (string) $event->source_environment_id)
            ->where('status', 'active')
            ->first();

        if ($sourceEnvironment === null || $sourceEnvironment->environment_id === null) {
            return 0;
        }

        $sourceProject = ProjectResource::query()
            ->where('product', 'deployer')
            ->where('resource_type', 'project')
            ->where('resource_id', (string) $event->source_project_id)
            ->where('status', 'active')
            ->first();

        if ($sourceProject === null) {
            return 0;
        }

        if ($sourceProject->project_id !== $sourceEnvironment->project_id) {
            throw new RuntimeException('The Deployer project mapping does not match its environment.');
        }

        $connections = ProjectConnection::query()
            ->where('project_id', $sourceEnvironment->project_id)
            ->whereIn('status', ['pending', 'active', 'failed'])
            ->whereNull('disconnected_at')
            ->with(['sourceResource', 'targetResource'])
            ->get();
        $created = 0;

        foreach ($connections as $connection) {
            $source = $connection->sourceResource;
            $target = $connection->targetResource;

            if (! in_array(ProjectConnectionCapability::DeploymentContext->value, (array) $connection->capabilities, true)
                || $source?->getKey() !== $sourceEnvironment->getKey()
                || $connection->source_environment_id !== $sourceEnvironment->environment_id
                || $target?->product !== 'monitor'
                || $target?->resource_type !== 'environment'
                || $target->environment_id === null
                || $connection->target_environment_id !== $target->environment_id
                || $target->status !== 'active') {
                continue;
            }

            $delivery = ProjectConnectionDelivery::query()->firstOrCreate(
                [
                    'source_event_id' => $event->getKey(),
                    'project_connection_id' => $connection->getKey(),
                ],
                [
                    'event_type' => $event->event_type,
                    'event_version' => $event->event_version,
                    'payload' => [
                        ...$payload,
                        'source_project_id' => (string) $event->source_project_id,
                        'source_environment_id' => (string) $event->source_environment_id,
                        'source_build_id' => (string) $event->source_build_id,
                        'canonical_project_id' => (string) $sourceEnvironment->project_id,
                        'canonical_environment_id' => (string) $sourceEnvironment->environment_id,
                        'target_environment_id' => (string) $target->resource_id,
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
}
