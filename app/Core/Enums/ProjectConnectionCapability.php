<?php

namespace App\Core\Enums;

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
}
