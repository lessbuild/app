<?php

namespace App\Services;

use App\Models\Environment;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Routing\UrlGenerator;

class DeploymentPreflightGuidance
{
    /**
     * Bind plan access and URL generation for first-deployment guidance.
     *
     * @param  Entitlements  $entitlements  Checks whether the workspace may use deployments.
     * @param  UrlGenerator  $url  Builds links to the relevant existing settings and recovery pages.
     */
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly UrlGenerator $url,
    ) {}

    /**
     * Add safe, actionable next steps without changing the persisted risk snapshot.
     *
     * @param  Repository  $repository  The source repository whose first deployment is being prepared.
     * @param  Environment|null  $environment  The resolved environment, if one is attached.
     * @param  User  $viewer  The authenticated account viewing the guidance.
     * @param  array{level: string, score: int, checks: list<array{name: string, status: string, detail: string}>}  $preflight  The existing technical preflight result.
     * @return array{completed: int, total: int, blockers: int, warnings: int, provider: array{status: string, title: string, detail: string, url: string, action: string}, plan: array{status: string, title: string, detail: string, url: string, action: string}, steps: list<array{status: string, title: string, detail: string, url: string, action: string}>} The view-only guidance summary.
     */
    public function for(
        Repository $repository,
        ?Environment $environment,
        User $viewer,
        array $preflight,
    ): array {
        $repository->loadMissing(['provider', 'website.server', 'organization']);
        $environment?->loadMissing('project');

        $steps = collect($preflight['checks'])
            ->filter(fn (array $check): bool => $check['status'] !== 'passed')
            ->map(fn (array $check): array => $this->stepForCheck($repository, $environment, $check))
            ->values()
            ->all();
        $provider = $this->providerCheck($repository);
        $plan = $this->planCheck($repository, $viewer);
        $allChecks = [...$preflight['checks'], $provider, $plan];

        foreach ([$provider, $plan] as $check) {
            if ($check['status'] !== 'passed') {
                $steps[] = $check;
            }
        }

        return [
            'completed' => collect($allChecks)->where('status', 'passed')->count(),
            'total' => count($allChecks),
            'blockers' => collect($allChecks)->where('status', 'failed')->count(),
            'warnings' => collect($allChecks)->where('status', 'warning')->count(),
            'provider' => $provider,
            'plan' => $plan,
            'steps' => $steps,
        ];
    }

    /**
     * Turn a technical preflight result into a link to the setting that can resolve it.
     *
     * @param  array{name: string, status: string, detail: string}  $check  The existing preflight check.
     * @return array{status: string, title: string, detail: string, url: string, action: string} An actionable view model.
     */
    private function stepForCheck(Repository $repository, ?Environment $environment, array $check): array
    {
        $website = $repository->website;
        $server = $website?->server;
        $repositoryUrl = $this->url->route('repositories.edit', $repository);
        $websiteUrl = $website && ! $website->trashed()
            ? $this->url->route('websites.edit', $website)
            : $this->url->route('websites.index');

        [$url, $action] = match ($check['name']) {
            'Server' => [
                $server ? $this->url->route('servers.show', $server) : $this->url->route('servers.create'),
                $server ? __('Review server status') : __('Create an application server'),
            ],
            'Website', 'Health verification', 'Release recovery' => [
                $websiteUrl,
                match ($check['name']) {
                    'Health verification' => __('Configure health verification'),
                    'Release recovery' => __('Configure release recovery'),
                    default => __('Review website status'),
                },
            ],
            'Environment', 'Production guardrail' => [
                $environment?->project_id
                    ? $this->url->route('projects.show', $environment->project_id)
                    : $this->url->route('projects.index'),
                $environment ? __('Review application environment') : __('Create an application environment'),
            ],
            'Push automation' => [
                $this->url->route('repositories.show', $repository).'#deployment-webhook',
                __('Configure push automation'),
            ],
            default => [$repositoryUrl, __('Review source settings')],
        };

        return [
            'status' => $check['status'],
            'title' => $check['name'],
            'detail' => $check['detail'],
            'url' => $url,
            'action' => $action,
        ];
    }

    /**
     * Explain the last known source-provider result without attempting a remote call from a page request.
     *
     * @return array{status: string, title: string, detail: string, url: string, action: string} The safe provider access result.
     */
    private function providerCheck(Repository $repository): array
    {
        $provider = $repository->provider;
        $settingsUrl = $provider
            ? $this->url->route('providers.edit', $provider)
            : $this->url->route('repositories.edit', $repository);
        $providerUrl = $provider
            ? $this->url->route('providers.show', $provider)
            : $settingsUrl;

        if (! $provider instanceof Provider || ! $provider->isSourceControl() || ! $provider->supportsRepositoryUrl($repository->url)) {
            return [
                'status' => 'failed',
                'title' => __('Source provider access'),
                'detail' => __('Choose a source-control provider whose host matches the repository URL before testing access.'),
                'url' => $settingsUrl,
                'action' => __('Review source settings'),
            ];
        }

        $latest = $provider->connectionChecks()
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->first();

        if ($latest?->http_status === 401) {
            return [
                'status' => 'failed',
                'title' => __('Source provider access'),
                'detail' => __('The latest provider check rejected the credential (HTTP 401). Replace or refresh it, then test the connection again.'),
                'url' => $settingsUrl,
                'action' => __('Update provider credential'),
            ];
        }

        if ($latest?->http_status === 403) {
            return [
                'status' => 'failed',
                'title' => __('Source provider access'),
                'detail' => __('The provider recognized the credential but denied access (HTTP 403). Grant repository read access or use a credential with the required scope, then test the connection again.'),
                'url' => $settingsUrl,
                'action' => __('Review provider permissions'),
            ];
        }

        if ($provider->connectionHealth() === Provider::CONNECTION_HEALTHY) {
            return [
                'status' => 'passed',
                'title' => __('Source provider access'),
                'detail' => __('The latest provider check accepted this credential. Repository-specific access is still checked when the source is used.'),
                'url' => $providerUrl,
                'action' => __('View provider checks'),
            ];
        }

        if ($provider->connectionHealth() === Provider::CONNECTION_FAILED) {
            return [
                'status' => 'warning',
                'title' => __('Source provider access'),
                'detail' => __('The latest provider connection check failed without a specific credential or permission result. Retry the check before launching.'),
                'url' => $providerUrl,
                'action' => __('Run connection check'),
            ];
        }

        return [
            'status' => 'warning',
            'title' => __('Source provider access'),
            'detail' => __('Run a connection check before the first deployment so credential access is confirmed.'),
            'url' => $providerUrl,
            'action' => __('Run connection check'),
        ];
    }

    /**
     * Report the existing deployment entitlement as a first-deployment prerequisite.
     *
     * @return array{status: string, title: string, detail: string, url: string, action: string} The safe plan access result.
     */
    private function planCheck(Repository $repository, User $viewer): array
    {
        $organization = $repository->organization ?: $viewer->currentOrganization;
        if ($organization && ! $this->entitlements->allows($organization, 'deployments')) {
            return [
                'status' => 'failed',
                'title' => __('Plan access'),
                'detail' => __('Your current plan does not include Git deployments. Upgrade your workspace to continue.'),
                'url' => $this->url->route('billing.index'),
                'action' => __('Upgrade workspace'),
            ];
        }

        return [
            'status' => 'passed',
            'title' => __('Plan access'),
            'detail' => __('Your current workspace plan includes Git deployments.'),
            'url' => $this->url->route('billing.index'),
            'action' => __('View plan'),
        ];
    }
}
