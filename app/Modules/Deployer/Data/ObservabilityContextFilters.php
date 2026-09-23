<?php

namespace App\Modules\Deployer\Data;

use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Models\OperationalIncident;
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

    /**
     * Reconstruct the canonical filter contract from persisted view data.
     *
     * Stored values are treated as untrusted legacy data and rejected when
     * they do not use the same finite shape as the HTTP boundary.
     *
     * @param  array<string, mixed>  $values  Persisted canonical filter values.
     * @return self|null Normalized filters, or null for an invalid stored shape.
     */
    public static function fromQueryParameters(array $values, ?CarbonInterface $now = null): ?self
    {
        $window = $values['window'] ?? null;
        $service = $values['service'] ?? null;
        $deployment = $values['deployment'] ?? null;
        $severity = $values['severity'] ?? null;

        if (! is_string($window) || ! array_key_exists($window, self::WINDOWS)
            || ! is_string($deployment) || ! array_key_exists($deployment, self::DEPLOYMENTS)
            || ! is_string($severity) || ! in_array($severity, self::SEVERITIES, true)) {
            return null;
        }

        if ($service === 'all') {
            $serviceId = null;
        } elseif (is_int($service) && $service > 0) {
            $serviceId = $service;
        } elseif (is_string($service) && filter_var($service, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false) {
            $serviceId = (int) $service;
        } else {
            return null;
        }

        return self::fromValues($window, $serviceId, $deployment, $severity, $now);
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
