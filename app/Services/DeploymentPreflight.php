<?php

namespace App\Services;

use App\Models\Environment;
use App\Models\Repository;
use App\Models\Server;
use App\Models\Website;

class DeploymentPreflight
{
    /** @return array{level: string, score: int, checks: list<array{name: string, status: string, detail: string}>} */
    public function assess(Repository $repository, ?Environment $environment): array
    {
        $repository->loadMissing(['provider', 'website.server']);
        $environment?->loadMissing(['variables', 'processes', 'resources']);
        $website = $repository->website;
        $server = $website?->server;

        $checks = [
            $this->check(
                'Source',
                $repository->provider?->isSourceControl() === true
                    && $repository->provider->supportsRepositoryUrl($repository->url)
                    && filled($repository->branch),
                'The repository host, URL, and deployment branch match.',
            ),
            $this->check('Server', $server?->provisioning_status === Server::STATUS_ACTIVE, 'The target server is active.'),
            $this->check('Website', $website?->provisioning_status === Website::STATUS_ACTIVE, 'The target website is active.'),
            $this->check('Health verification', $website?->health_check_enabled === true, 'A post-deployment health check is enabled.', 'warning'),
            $this->check('Release recovery', ($website?->release_retention ?? 0) >= 2, 'At least two releases are retained.', 'warning'),
            $this->check('Environment', $environment !== null, 'Deployment configuration is snapshotted for this release.', 'warning'),
            $this->check('Push automation', $repository->webhook_enabled === true, 'Authenticated push deployments are enabled.', 'warning'),
        ];

        if ($environment?->type === 'production') {
            $checks[] = $this->check('Production guardrail', $environment->requires_deployment_approval || $environment->deployment_window_days !== null, 'Approval or a deployment window protects production.', 'warning');
        }

        $failed = collect($checks)->where('status', 'failed')->count();
        $warnings = collect($checks)->where('status', 'warning')->count();
        $score = max(0, 100 - ($failed * 35) - ($warnings * 10));

        return [
            'level' => $failed > 0 ? 'blocked' : ($warnings > 0 ? 'review' : 'ready'),
            'score' => $score,
            'checks' => $checks,
        ];
    }

    private function check(string $name, bool $passes, string $detail, string $failure = 'failed'): array
    {
        return ['name' => $name, 'status' => $passes ? 'passed' : $failure, 'detail' => $detail];
    }
}
