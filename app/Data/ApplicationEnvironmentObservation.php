<?php

namespace App\Data;

class ApplicationEnvironmentObservation
{
    public const STATUS_OBSERVED = 'observed';

    public const STATUS_UNAVAILABLE = 'unavailable';

    public const STATUS_UNKNOWN = 'unknown';

    /**
     * Carry a one-time, provider-backed observation of a recorded environment.
     *
     * @param  list<array{field: string, recorded: string, observed: string, status: 'match'|'different'}>  $fields  Normalized server metadata only; credentials and response bodies are excluded.
     * @param  'ready'|'not_ready'|'unknown'  $providerReadiness  Provider-reported server readiness, not application health.
     * @param  string|null  $providerState  Provider lifecycle value, if safely reported.
     */
    public function __construct(
        public readonly int $environmentId,
        public readonly string $environmentName,
        public readonly string $providerName,
        public readonly string $status,
        public readonly string $message,
        public readonly array $fields = [],
        public readonly string $providerReadiness = CloudServerData::READINESS_UNKNOWN,
        public readonly ?string $providerState = null,
    ) {}

    /**
     * Determine whether the provider returned any metadata that differs from the recorded server.
     *
     * @return bool Whether a supported observed field differs from local recorded state.
     */
    public function hasDifferences(): bool
    {
        return collect($this->fields)->contains(fn (array $field): bool => $field['status'] === 'different');
    }
}
