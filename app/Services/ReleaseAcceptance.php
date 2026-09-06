<?php

namespace App\Services;

use App\Models\BackupRestore;
use App\Models\Build;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\Website;
use App\Models\WebsiteBackup;
use Illuminate\Support\Carbon;

class ReleaseAcceptance
{
    /** @return list<array{name: string, passed: bool, detail: string}> */
    public function audit(Project $project, ?Carbon $since = null, ?string $provider = null): array
    {
        $project->loadMissing(['environments.server.provider', 'environments.website']);
        $candidates = $project->environments
            ->filter(fn ($environment): bool => $environment->server?->provider
                && in_array($environment->server->provider->provider, Provider::SERVER_TYPES, true)
                && ($provider === null || $environment->server->provider->provider === $provider));

        return $candidates
            ->map(fn ($environment): array => $this->auditEnvironment($environment, $since))
            ->sortByDesc(fn (array $checks): int => collect($checks)->where('passed', true)->count())
            ->first() ?? $this->missingChecks();
    }

    /** @return list<array{name: string, passed: bool, detail: string}> */
    private function auditEnvironment(Environment $environment, ?Carbon $since): array
    {
        $server = $environment->server;
        $website = $environment->website;
        $serverReady = $server->provisioning_status === Server::STATUS_ACTIVE
            && filled($server->identifier)
            && $this->atOrAfter($server->provisioned_at, $since);
        $websiteReady = $website?->provisioning_status === Website::STATUS_ACTIVE
            && $this->atOrAfter($website?->provisioned_at, $since);

        $builds = Build::query()
            ->where('environment_id', $environment->id)
            ->where('status', Build::STATUS_SUCCEEDED)
            ->when($since, fn ($query) => $query->where('finished_at', '>=', $since));
        $successfulDeployment = (clone $builds)->where('trigger_source', '!=', Build::TRIGGER_ROLLBACK)->exists();
        $rollback = (clone $builds)->where('trigger_source', Build::TRIGGER_ROLLBACK)->latest('finished_at')->first();
        $successfulRollback = $rollback !== null;

        $healthAfter = collect([$since, $rollback?->finished_at])->filter()->sort()->last();
        $healthy = $website?->health_check_enabled === true
            && $website->health_status === Website::HEALTH_HEALTHY
            && $website->healthChecks()
                ->where('successful', true)
                ->when($healthAfter, fn ($query) => $query->where('checked_at', '>=', $healthAfter))
                ->exists();

        $backups = WebsiteBackup::query()
            ->where('website_id', $website?->id)
            ->where('status', WebsiteBackup::STATUS_SUCCEEDED)
            ->whereNotNull('snapshot_id')
            ->whereNotNull('completed_at')
            ->when($since, fn ($query) => $query->where('completed_at', '>=', $since));
        $backupIds = (clone $backups)->pluck('id');
        $backup = $backupIds->isNotEmpty();
        $restore = BackupRestore::query()
            ->whereIn('website_backup_id', $backupIds)
            ->where('status', BackupRestore::STATUS_SUCCEEDED)
            ->whereNotNull('completed_at')
            ->when($since, fn ($query) => $query->where('completed_at', '>=', $since))
            ->exists();

        return [
            $this->result('Cloud provisioning', $serverReady, 'An active provider-created server has completed provisioning'),
            $this->result('Website provisioning', $websiteReady, 'An application website is active'),
            $this->result('Deployment', $successfulDeployment, 'A non-rollback deployment completed successfully'),
            $this->result('Health verification', $healthy, 'An enabled website health check completed successfully'),
            $this->result('Rollback', $successfulRollback, 'A rollback deployment completed successfully'),
            $this->result('Offsite backup', $backup, 'A managed website backup completed successfully'),
            $this->result('Restore drill', $restore, 'A managed backup restore completed successfully'),
        ];
    }

    /** @return list<array{name: string, passed: bool, detail: string}> */
    private function missingChecks(): array
    {
        return collect([
            'Cloud provisioning', 'Website provisioning', 'Deployment', 'Health verification',
            'Rollback', 'Offsite backup', 'Restore drill',
        ])->map(fn (string $name): array => $this->result($name, false, ''))->all();
    }

    private function atOrAfter($recordedAt, ?Carbon $since): bool
    {
        return $recordedAt !== null && ($since === null || $recordedAt->greaterThanOrEqualTo($since));
    }

    private function result(string $name, bool $passed, string $successDetail): array
    {
        return ['name' => $name, 'passed' => $passed, 'detail' => $passed ? $successDetail : 'Required acceptance evidence is missing'];
    }
}
