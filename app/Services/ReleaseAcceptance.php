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
                && $environment->website !== null
                && (int) $environment->website->server_id === (int) $environment->server_id
                && (int) $environment->server->organization_id === (int) $project->organization_id
                && (int) $environment->website->organization_id === (int) $project->organization_id
                && (int) $environment->server->provider->organization_id === (int) $project->organization_id
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
        $best = $this->missingChecks();
        foreach ($this->releaseChains($environment, $since) as $chain) {
            $checks = $this->auditChain($environment, $since, $chain);
            if (collect($checks)->every('passed')) {
                return $checks;
            }
            if (collect($checks)->where('passed', true)->count() > collect($best)->where('passed', true)->count()) {
                $best = $checks;
            }
        }

        return $best;
    }

    private function auditChain(Environment $environment, ?Carbon $since, array $chain): array
    {
        $server = $environment->server;
        $website = $environment->website;
        $initial = $chain['initial'];
        $second = $chain['second'];
        $rollback = $chain['rollback'];

        $serverReady = $server->provisioning_status === Server::STATUS_ACTIVE
            && filled($server->identifier)
            && $this->between($server->provisioned_at, $since, $initial?->finished_at);
        $websiteReady = $website?->provisioning_status === Website::STATUS_ACTIVE
            && $this->between($website?->provisioned_at, $since, $initial?->finished_at);

        $backups = WebsiteBackup::query()
            ->with('destination')
            ->when($rollback === null, fn ($query) => $query->whereRaw('1 = 0'))
            ->where('website_id', $website?->id)
            ->where('status', WebsiteBackup::STATUS_SUCCEEDED)
            ->whereNotNull('snapshot_id')
            ->whereNotNull('completed_at')
            ->when($rollback?->finished_at, fn ($query, $finishedAt) => $query->where('completed_at', '>=', $finishedAt))
            ->when($since, fn ($query) => $query->where('completed_at', '>=', $since))
            ->oldest('completed_at')
            ->get()
            ->filter(fn (WebsiteBackup $candidate): bool => preg_match('/\A[a-f0-9]{8,64}\z/D', (string) $candidate->snapshot_id) === 1
                && $candidate->destination !== null
                && (int) $candidate->destination->organization_id === (int) $website->organization_id
                && $this->between($candidate->https_verified_at, $since, $candidate->completed_at));

        $backup = $backups->first();
        $restore = null;
        foreach ($backups as $candidate) {
            $candidateRestore = BackupRestore::query()
                ->where('website_backup_id', $candidate->id)
                ->where('status', BackupRestore::STATUS_SUCCEEDED)
                ->whereNotNull('completed_at')
                ->where('completed_at', '>=', $candidate->completed_at)
                ->oldest('completed_at')
                ->first();
            if ($candidateRestore !== null && ($restore === null || $candidateRestore->completed_at->lessThan($restore->completed_at))) {
                $backup = $candidate;
                $restore = $candidateRestore;
            }
        }

        $healthy = $website?->health_check_enabled === true
            && $website->health_status === Website::HEALTH_HEALTHY
            && $restore !== null
            && $website->healthChecks()
                ->where('successful', true)
                ->where('checked_at', '>=', $restore->completed_at)
                ->exists();

        return [
            $this->result('Cloud provisioning', $serverReady, 'An active provider-created server has completed provisioning'),
            $this->result('Website provisioning', $websiteReady, 'An application website is active'),
            $this->result('Deployment', $initial !== null && $second !== null, 'Two distinct source revisions deployed successfully in order'),
            $this->result('Rollback', $rollback !== null, 'The first revision was restored after the second deployment'),
            $this->result('Offsite backup', $backup !== null, 'A verified HTTPS destination captured a later managed backup'),
            $this->result('Restore drill', $restore !== null, 'That exact backup was restored after it completed'),
            $this->result('Health verification', $healthy, 'An enabled website health check passed after the restore'),
        ];
    }

    /** @return iterable<array{initial: ?Build, second: ?Build, rollback: ?Build}> */
    private function releaseChains(Environment $environment, ?Carbon $since): iterable
    {
        $rollbacks = Build::query()
            ->with('rolledBackFrom')
            ->where('environment_id', $environment->id)
            ->where('status', Build::STATUS_SUCCEEDED)
            ->where('trigger_source', Build::TRIGGER_ROLLBACK)
            ->whereNotNull('finished_at')
            ->when($since, fn ($query) => $query->where('finished_at', '>=', $since))
            ->latest('finished_at')
            ->get();

        foreach ($rollbacks as $rollback) {
            $initial = $rollback->rolledBackFrom;
            if (! $this->validInitialBuild($initial, $environment, $since, $rollback)) {
                continue;
            }

            $second = Build::query()
                ->where('environment_id', $environment->id)
                ->where('repository_id', $initial->repository_id)
                ->where('status', Build::STATUS_SUCCEEDED)
                ->where('trigger_source', '!=', Build::TRIGGER_ROLLBACK)
                ->whereNotNull('finished_at')
                ->where('finished_at', '>', $initial->finished_at)
                ->where('finished_at', '<=', $rollback->finished_at)
                ->where('revision', '!=', $initial->revision)
                ->oldest('finished_at')
                ->get()->first(fn (Build $build): bool => $this->validRevision($build->revision));

            if ($second !== null && $this->validRevision($second->revision)) {
                yield compact('initial', 'second', 'rollback');
            }
        }

        yield ['initial' => null, 'second' => null, 'rollback' => null];
    }

    private function validInitialBuild(?Build $initial, Environment $environment, ?Carbon $since, Build $rollback): bool
    {
        return $initial !== null
            && $initial->repository !== null
            && (int) $initial->repository->organization_id === (int) $environment->website->organization_id
            && (int) $initial->repository->website_id === (int) $environment->website_id
            && (int) $initial->repository_id === (int) $rollback->repository_id
            && $initial->release_name === $rollback->release_name
            && $initial->release_path === $rollback->release_path
            && (int) $initial->environment_id === (int) $environment->id
            && $initial->status === Build::STATUS_SUCCEEDED
            && $initial->trigger_source !== Build::TRIGGER_ROLLBACK
            && $this->validRevision($initial->revision)
            && $initial->finished_at !== null
            && $this->atOrAfter($initial->finished_at, $since)
            && $initial->finished_at->lessThan($rollback->finished_at)
            && hash_equals((string) $initial->revision, (string) $rollback->revision)
            && filled($rollback->release_name)
            && filled($rollback->release_path);
    }

    private function validRevision(?string $revision): bool
    {
        return preg_match('/\A[a-f0-9]{40,64}\z/Di', (string) $revision) === 1;
    }

    /** @return list<array{name: string, passed: bool, detail: string}> */
    private function missingChecks(): array
    {
        return collect([
            'Cloud provisioning', 'Website provisioning', 'Deployment', 'Rollback',
            'Offsite backup', 'Restore drill', 'Health verification',
        ])->map(fn (string $name): array => $this->result($name, false, ''))->all();
    }

    private function atOrAfter($recordedAt, ?Carbon $since): bool
    {
        return $recordedAt !== null && ($since === null || $recordedAt->greaterThanOrEqualTo($since));
    }

    private function between($recordedAt, ?Carbon $since, ?Carbon $before): bool
    {
        return $this->atOrAfter($recordedAt, $since)
            && ($before === null || $recordedAt->lessThanOrEqualTo($before));
    }

    private function result(string $name, bool $passed, string $successDetail): array
    {
        return ['name' => $name, 'passed' => $passed, 'detail' => $passed ? $successDetail : 'Required acceptance evidence is missing'];
    }
}
