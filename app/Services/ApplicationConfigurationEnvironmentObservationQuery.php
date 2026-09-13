<?php

namespace App\Services;

use App\Data\ApplicationEnvironmentObservation;
use App\Data\CloudServerData;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use Throwable;

class ApplicationConfigurationEnvironmentObservationQuery
{
    public function __construct(private readonly ServerProviderResolver $providers) {}

    /**
     * Fetch one project's selected environment and explicitly observe supported server metadata.
     *
     * The read is intentionally opt-in. It does not persist observations, reconcile local state,
     * mutate provider resources, or inspect website environments, variables or resource secrets.
     *
     * @param  Project  $project  Project whose organization scopes the environment and placement.
     * @param  int  $environmentId  The selected project environment identifier.
     * @return ApplicationEnvironmentObservation|null A safe result, or null when the environment is no longer in the project.
     */
    public function for(Project $project, int $environmentId): ?ApplicationEnvironmentObservation
    {
        /** @var Environment|null $environment */
        $environment = $project->environments()
            ->with([
                'server' => fn ($query) => $query->select([
                    'servers.id', 'servers.organization_id', 'servers.provider_id', 'servers.identifier',
                    'servers.name', 'servers.region', 'servers.size', 'servers.image', 'servers.public_ip',
                    'servers.private_ip',
                ]),
                'server.provider' => fn ($query) => $query->select(['providers.id', 'providers.organization_id', 'providers.name', 'providers.provider', 'providers.token']),
            ])
            ->whereKey($environmentId)
            ->first();

        if (! $environment) {
            return null;
        }

        $server = $environment->server;

        if (! $server instanceof Server || (int) $server->organization_id !== (int) $project->organization_id) {
            return $this->unavailable($environment, __('The environment has no available workspace server placement.'));
        }

        $provider = $server->provider;

        if (! $provider instanceof Provider || (int) $provider->organization_id !== (int) $project->organization_id) {
            return $this->unavailable($environment, __('The environment server has no available workspace provider connection.'));
        }

        if (blank($server->identifier)) {
            return $this->unavailable($environment, __('The provider has not assigned a server identifier yet.'));
        }

        try {
            $client = $this->providers->resolve($provider);
            $observed = $client->server($server->identifier);
        } catch (Throwable) {
            return new ApplicationEnvironmentObservation(
                environmentId: (int) $environment->id,
                environmentName: (string) $environment->name,
                providerName: (string) ($provider->name ?: $provider->provider),
                status: ApplicationEnvironmentObservation::STATUS_UNKNOWN,
                message: __('The provider could not confirm the current server state. Check the connection and try again.'),
            );
        }

        return new ApplicationEnvironmentObservation(
            environmentId: (int) $environment->id,
            environmentName: (string) $environment->name,
            providerName: $client->name(),
            status: ApplicationEnvironmentObservation::STATUS_OBSERVED,
            message: __('The provider returned the supported server metadata below. Differences are informational only.'),
            fields: $this->fields($server, $observed),
        );
    }

    /**
     * Build a safe unavailable result without attempting a remote request.
     *
     * @param  Environment  $environment  The project-scoped environment being observed.
     * @param  string  $message  A non-sensitive explanation suitable for the page.
     * @return ApplicationEnvironmentObservation The unavailable observation.
     */
    private function unavailable(Environment $environment, string $message): ApplicationEnvironmentObservation
    {
        return new ApplicationEnvironmentObservation(
            environmentId: (int) $environment->id,
            environmentName: (string) $environment->name,
            providerName: __('Unavailable'),
            status: ApplicationEnvironmentObservation::STATUS_UNAVAILABLE,
            message: $message,
        );
    }

    /**
     * Compare only the fields normalized by the shared server-provider contract.
     *
     * @param  Server  $server  Local recorded server state.
     * @param  CloudServerData  $observed  Current provider state.
     * @return list<array{field: string, recorded: string, observed: string, status: 'match'|'different'}> Safe field comparisons.
     */
    private function fields(Server $server, CloudServerData $observed): array
    {
        $values = [
            ['field' => 'Provider identifier', 'recorded' => $server->identifier, 'observed' => $observed->identifier],
            ['field' => 'Server name', 'recorded' => $server->name, 'observed' => $observed->name],
            ['field' => 'Region', 'recorded' => $server->region, 'observed' => $observed->region],
            ['field' => 'Size', 'recorded' => $server->size, 'observed' => $observed->size],
            ['field' => 'Image', 'recorded' => $server->image, 'observed' => $observed->image],
            ['field' => 'Public IP', 'recorded' => $server->public_ip, 'observed' => $observed->publicIp],
            ['field' => 'Private IP', 'recorded' => $server->private_ip, 'observed' => $observed->privateIp],
        ];

        return array_map(fn (array $value): array => [
            'field' => $value['field'],
            'recorded' => $this->display($value['recorded']),
            'observed' => $this->display($value['observed']),
            'status' => $this->same($value['recorded'], $value['observed']) ? 'match' : 'different',
        ], $values);
    }

    /**
     * Normalize values for comparison without treating an absent field as a secret or an error.
     *
     * @param  mixed  $value  Provider or local metadata value.
     * @return string The normalized comparison value.
     */
    private function normalize(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Compare normalized provider and local metadata.
     *
     * @param  mixed  $recorded  Local recorded value.
     * @param  mixed  $observed  Provider-observed value.
     * @return bool Whether both values are equal after normalization.
     */
    private function same(mixed $recorded, mixed $observed): bool
    {
        return $this->normalize($recorded) === $this->normalize($observed);
    }

    /**
     * Format an optional metadata field for a safe table cell.
     *
     * @param  mixed  $value  Local or provider metadata.
     * @return string A bounded display value or an explicit unavailable marker.
     */
    private function display(mixed $value): string
    {
        $value = $this->normalize($value);

        return $value === '' ? __('Not reported') : $value;
    }
}
