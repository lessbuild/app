<?php

namespace App\Core\Enums;

use App\Core\Models\ProjectResource;

enum ProjectConnectionCapability: string
{
    case DeploymentContext = 'deployment_context';
    case ReleaseAnnotations = 'release_annotations';
    case IncidentAnnotations = 'incident_annotations';
    case TrafficContext = 'traffic_context';

    /** @return list<self> */
    public static function supportedBetween(string $source, string $target): array
    {
        return match ([$source, $target]) {
            ['deployer', 'monitor'] => [self::DeploymentContext],
            ['deployer', 'analytics'] => [self::ReleaseAnnotations],
            ['monitor', 'analytics'] => [self::IncidentAnnotations],
            ['analytics', 'monitor'] => [self::TrafficContext],
            default => [],
        };
    }

    public function supportsResources(ProjectResource $source, ProjectResource $target): bool
    {
        return match ($this) {
            self::DeploymentContext => $source->product === 'deployer'
                && $source->resource_type === 'environment'
                && $target->product === 'monitor'
                && $target->resource_type === 'environment',
            self::ReleaseAnnotations => $source->product === 'deployer'
                && $source->resource_type === 'environment'
                && $target->product === 'analytics'
                && $target->resource_type === 'site',
            self::IncidentAnnotations => $source->product === 'monitor'
                && $source->resource_type === 'environment'
                && $target->product === 'analytics'
                && $target->resource_type === 'site',
            self::TrafficContext => $source->product === 'analytics'
                && $source->resource_type === 'site'
                && $target->product === 'monitor'
                && $target->resource_type === 'environment',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::DeploymentContext => __('Deployment context'),
            self::ReleaseAnnotations => __('Release annotations'),
            self::IncidentAnnotations => __('Incident annotations'),
            self::TrafficContext => __('Traffic context'),
        };
    }

    public function sourceProduct(): string
    {
        return match ($this) {
            self::DeploymentContext, self::ReleaseAnnotations => 'deployer',
            self::IncidentAnnotations => 'monitor',
            self::TrafficContext => 'analytics',
        };
    }

    public function targetProduct(): string
    {
        return match ($this) {
            self::DeploymentContext => 'monitor',
            self::ReleaseAnnotations, self::IncidentAnnotations => 'analytics',
            self::TrafficContext => 'monitor',
        };
    }

    public function sourceResourceType(): string
    {
        return match ($this) {
            self::TrafficContext => 'site',
            self::DeploymentContext, self::ReleaseAnnotations, self::IncidentAnnotations => 'environment',
        };
    }

    public function targetResourceType(): string
    {
        return match ($this) {
            self::ReleaseAnnotations, self::IncidentAnnotations => 'site',
            self::DeploymentContext, self::TrafficContext => 'environment',
        };
    }

    public function hasDeliveryHandler(): bool
    {
        return $this === self::DeploymentContext;
    }
}
