<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Contracts\ProjectConnectionDiagnosticProvider;
use App\Core\Data\Connections\ProjectConnectionDiagnostic;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project as CoreProject;
use App\Core\Models\ProjectConnection;
use App\Core\Models\ProjectResource;
use App\Modules\Deployer\Models\Provider;
use App\Modules\Deployer\Models\ProviderConnectionCheck;
use Illuminate\Support\Collection;

final class DeployerProjectConnectionDiagnosticProvider implements ProjectConnectionDiagnosticProvider
{
    public function __construct(private readonly DeployerProjectLink $projects) {}

    public function diagnose(
        PlatformUser $user,
        ProjectConnection $connection,
        ProjectResource $resource,
    ): ?ProjectConnectionDiagnostic {
        if ((string) $resource->project_id !== (string) $connection->project_id) {
            return null;
        }

        $project = CoreProject::query()->find($connection->project_id);
        if ($project === null) {
            return null;
        }

        $environment = $this->projects->accessibleEnvironment($user, $project, $resource);
        if ($environment === null) {
            return null;
        }

        $providerIds = collect([
            $environment->server?->provider_id,
            $environment->website?->server?->provider_id,
        ])
            ->merge($environment->website?->repositories->pluck('provider_id') ?? collect())
            ->filter()
            ->map(static fn ($id): string => (string) $id)
            ->unique()
            ->take(51)
            ->values();

        if ($providerIds->isEmpty()) {
            return null;
        }

        $providers = Provider::query()
            ->whereKey($providerIds)
            ->orderBy('id')
            ->get([
                'id',
                'name',
                'provider',
                'connection_status',
                'connection_checked_at',
                'connection_check_interval_minutes',
            ]);
        $checks = ProviderConnectionCheck::query()
            ->whereIn('provider_id', $providers->modelKeys())
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->get(['id', 'provider_id', 'successful', 'http_status', 'checked_at'])
            ->unique('provider_id')
            ->keyBy(static fn (ProviderConnectionCheck $check): string => (string) $check->provider_id);

        return $this->highestPriorityIssue($providers, $checks);
    }

    /**
     * @param  Collection<int, Provider>  $providers
     * @param  Collection<string, ProviderConnectionCheck>  $checks
     */
    private function highestPriorityIssue(Collection $providers, Collection $checks): ?ProjectConnectionDiagnostic
    {
        $issues = [];

        foreach ($providers as $provider) {
            $check = $checks->get((string) $provider->getKey());
            $issue = $this->forProvider($provider, $check);

            if ($issue !== null) {
                $issues[] = $issue;
            }
        }

        usort($issues, static fn (array $left, array $right): int => $left['priority'] <=> $right['priority']);

        return $issues[0]['diagnostic'] ?? null;
    }

    /**
     * @return array{priority: int, diagnostic: ProjectConnectionDiagnostic}|null
     */
    private function forProvider(Provider $provider, ?ProviderConnectionCheck $check): ?array
    {
        $label = $provider->name ?: str((string) $provider->provider)->headline()->toString();
        $checkedAt = $check?->checked_at ?? $provider->connection_checked_at;

        if ($check !== null && ! $check->successful && $check->http_status === 401) {
            return [
                'priority' => 1,
                'diagnostic' => new ProjectConnectionDiagnostic(
                    tone: 'warning',
                    status: __('Credential rejected'),
                    summary: __(':provider rejected its saved credential (HTTP 401).', ['provider' => $label]),
                    detail: __('The credential may have expired, been revoked, or no longer be valid.'),
                    nextStep: __('Replace or refresh the credential in Deployer, then run a new provider check.'),
                    lastAttemptAt: null,
                    lastSucceededAt: null,
                    lastObservedAt: $checkedAt,
                    lastObservedLabel: __('Last provider check'),
                    priority: 10,
                ),
            ];
        }

        if ($check !== null && ! $check->successful && $check->http_status === 403) {
            return [
                'priority' => 2,
                'diagnostic' => new ProjectConnectionDiagnostic(
                    tone: 'warning',
                    status: __('Provider permission denied'),
                    summary: __(':provider denied the saved credential (HTTP 403).', ['provider' => $label]),
                    detail: __('The credential needs access to the resource Deployer uses for this environment.'),
                    nextStep: __('Review the provider credential scopes or workspace permissions, then run a new check.'),
                    lastAttemptAt: null,
                    lastSucceededAt: null,
                    lastObservedAt: $checkedAt,
                    lastObservedLabel: __('Last provider check'),
                    priority: 12,
                ),
            ];
        }

        if ($check !== null && ! $check->successful) {
            $status = $check->http_status === 429
                ? __('Provider rate limited')
                : ($check->http_status !== null && $check->http_status >= 500
                    ? __('Provider unavailable')
                    : __('Provider check failed'));

            return [
                'priority' => 3,
                'diagnostic' => new ProjectConnectionDiagnostic(
                    tone: 'warning',
                    status: $status,
                    summary: __('Deployer could not confirm the :provider connection.', ['provider' => $label]),
                    detail: $check->http_status === 429
                        ? __('The provider limited its connection check; the saved credential was not confirmed.')
                        : __('The latest provider check did not succeed. Private provider response details are hidden.'),
                    nextStep: $check->http_status === 429
                        ? __('Wait before running another check, then review the environment again.')
                        : __('Retry the provider check in Deployer and review the provider if it keeps failing.'),
                    lastAttemptAt: null,
                    lastSucceededAt: null,
                    lastObservedAt: $checkedAt,
                    lastObservedLabel: __('Last provider check'),
                    priority: 15,
                ),
            ];
        }

        if ($check === null && $provider->connectionHealth() === Provider::CONNECTION_FAILED) {
            return [
                'priority' => 3,
                'diagnostic' => new ProjectConnectionDiagnostic(
                    tone: 'warning',
                    status: __('Provider check failed'),
                    summary: __('Deployer has a failed connection state for :provider.', ['provider' => $label]),
                    detail: __('The retained result is unavailable, so the cause cannot be confirmed here.'),
                    nextStep: __('Run a new provider check in Deployer to get current guidance.'),
                    lastAttemptAt: null,
                    lastSucceededAt: null,
                    lastObservedAt: $checkedAt,
                    lastObservedLabel: __('Last provider check'),
                    priority: 15,
                ),
            ];
        }

        if ($check === null || $checkedAt === null || $provider->connectionHealth() === Provider::CONNECTION_UNCHECKED) {
            return [
                'priority' => 4,
                'diagnostic' => new ProjectConnectionDiagnostic(
                    tone: 'info',
                    status: __('Credential not checked'),
                    summary: __('Deployer has no confirmed provider check for :provider.', ['provider' => $label]),
                    detail: __('The project connection view does not contact providers or validate saved credentials.'),
                    nextStep: __('Run a provider connection check in Deployer to verify access.'),
                    lastAttemptAt: null,
                    lastSucceededAt: null,
                    lastObservedAt: $checkedAt,
                    lastObservedLabel: __('Last provider check'),
                    priority: 30,
                ),
            ];
        }

        $interval = (int) $provider->connection_check_interval_minutes;
        $interval = in_array($interval, Provider::CONNECTION_CHECK_INTERVALS, true)
            ? $interval
            : Provider::DEFAULT_CONNECTION_CHECK_INTERVAL_MINUTES;

        if ($checkedAt->lessThanOrEqualTo(now()->subMinutes($interval))) {
            return [
                'priority' => 4,
                'diagnostic' => new ProjectConnectionDiagnostic(
                    tone: 'info',
                    status: __('Credential check overdue'),
                    summary: __('The last :provider credential check is older than its configured interval.', ['provider' => $label]),
                    detail: __('The stored successful check may no longer reflect current provider access.'),
                    nextStep: __('Run a new provider connection check in Deployer to confirm access.'),
                    lastAttemptAt: null,
                    lastSucceededAt: null,
                    lastObservedAt: $checkedAt,
                    lastObservedLabel: __('Last provider check'),
                    priority: 25,
                ),
            ];
        }

        return null;
    }
}
