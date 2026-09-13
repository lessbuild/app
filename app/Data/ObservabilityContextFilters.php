<?php

namespace App\Data;

use App\Models\Build;
use App\Models\OperationalIncident;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class ObservabilityContextFilters
{
    /** @var array<string, int> Supported investigation windows in hours. */
    public const WINDOWS = [
        '24h' => 24,
        '7d' => 168,
        '30d' => 720,
    ];

    /** @var array<string, list<string>> Deployment status groups accepted by the context read. */
    public const DEPLOYMENTS = [
        'all' => [],
        'active' => Build::ACTIVE_STATUSES,
        'successful' => [Build::STATUS_SUCCEEDED],
        'unsuccessful' => [Build::STATUS_REJECTED, Build::STATUS_FAILED, Build::STATUS_CANCELED],
    ];

    /** @var list<string> Incident severities accepted by the context read. */
    public const SEVERITIES = ['all', ...OperationalIncident::SEVERITIES];

    public readonly CarbonImmutable $since;

    /**
     * Carry the finite, normalized window used by the environment evidence read.
     *
     * @param  '24h'|'7d'|'30d'  $window  User-facing investigation window.
     * @param  CarbonInterface  $now  Clock value captured at the HTTP boundary.
     */
    public function __construct(
        public readonly string $window,
        public readonly ?int $serviceId,
        public readonly string $deployment,
        public readonly string $severity,
        CarbonInterface $now,
    ) {
        $this->since = $now->copy()->subHours(self::WINDOWS[$window])->toImmutable();
    }

    /**
     * Build filters from the validated window while keeping the query independent of HTTP input.
     *
     * @param  '24h'|'7d'|'30d'  $window  Validated investigation window.
     */
    public static function fromWindow(string $window, ?CarbonInterface $now = null): self
    {
        return self::fromValues($window, null, 'all', 'all', $now);
    }

    /**
     * Build the complete immutable filter boundary from validated context values.
     *
     * @param  '24h'|'7d'|'30d'  $window  Validated investigation window.
     * @param  'all'|'active'|'successful'|'unsuccessful'  $deployment  Validated deployment status group.
     * @param  'all'|'minor'|'major'|'critical'  $severity  Validated incident severity.
     */
    public static function fromValues(
        string $window,
        ?int $serviceId,
        string $deployment,
        string $severity,
        ?CarbonInterface $now = null,
    ): self {
        return new self($window, $serviceId, $deployment, $severity, $now ?? now());
    }

    /** @return list<string> Status values represented by the selected deployment group. */
    public function deploymentStatuses(): array
    {
        return self::DEPLOYMENTS[$this->deployment];
    }

    /**
     * Return only the validated values used to reproduce this context read.
     *
     * @return array{window: string, service: string, deployment: string, severity: string}
     */
    public function queryParameters(): array
    {
        return [
            'window' => $this->window,
            'service' => $this->serviceId === null ? 'all' : (string) $this->serviceId,
            'deployment' => $this->deployment,
            'severity' => $this->severity,
        ];
    }
}
