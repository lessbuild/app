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
use Carbon\CarbonInterface;
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

    /**
     * Evaluate provisioning, deployment, rollback, backup, restore and health evidence in temporal order.
     *
     * @param  Environment  $environment  The environment whose server and website supply release evidence.
     * @param  Carbon|null  $since  An optional earliest accepted evidence timestamp.
     * @param  array{initial: Build|null, second: Build|null, rollback: Build|null}  $chain  The selected deployment sequence to validate against later backup and restore records.
     * @return list<array{name: string, passed: bool, detail: string}> Seven acceptance checks for the selected evidence chain.
     */
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

    /**
     * Check that the original successful release belongs to this environment and matches the rollback.
     *
     * @param  Build|null  $initial  The candidate original deployment, or null when it cannot be found.
     * @param  Environment  $environment  The environment and workspace the original release must belong to.
     * @param  Carbon|null  $since  The optional earliest accepted deployment timestamp.
     * @param  Build  $rollback  The later rollback whose repository, revision and release paths must match.
     * @return bool Whether the candidate supplies coherent pre-rollback evidence.
     */
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

    /**
     * Check whether a recorded revision has a 40- to 64-character hexadecimal form.
     *
     * @param  string|null  $revision  The optional stored source revision.
     * @return bool True only when the complete value matches the expected hexadecimal length.
     */
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

    /**
     * Require an evidence timestamp and optionally enforce its lower bound.
     *
     * @param  CarbonInterface|null  $recordedAt  The recorded event time, or null for missing evidence.
     * @param  Carbon|null  $since  The optional inclusive lower bound.
     * @return bool Whether the timestamp exists and is at or after the lower bound.
     */
    private function atOrAfter(?CarbonInterface $recordedAt, ?Carbon $since): bool
    {
        return $recordedAt !== null && ($since === null || $recordedAt->greaterThanOrEqualTo($since));
    }

    /**
     * Check evidence timestamps against optional inclusive lower and upper bounds.
     *
     * @param  CarbonInterface|null  $recordedAt  The recorded event time to validate.
     * @param  Carbon|null  $since  The optional inclusive lower bound.
     * @param  Carbon|null  $before  The optional inclusive upper bound.
     * @return bool Whether the timestamp exists and falls within all supplied bounds.
     */
    private function between(?CarbonInterface $recordedAt, ?Carbon $since, ?Carbon $before): bool
    {
        return $this->atOrAfter($recordedAt, $since)
            && ($before === null || $recordedAt->lessThanOrEqualTo($before));
    }

    /**
     * Format one acceptance result without claiming success when evidence is missing.
     *
     * @param  string  $name  The acceptance-check label.
     * @param  bool  $passed  Whether the required evidence was found.
     * @param  string  $successDetail  The explanation included only when the check passes.
     * @return array{name: string, passed: bool, detail: string} The check outcome and either its success detail or missing-evidence message.
     */
    private function result(string $name, bool $passed, string $successDetail): array
    {
        return ['name' => $name, 'passed' => $passed, 'detail' => $passed ? $successDetail : 'Required acceptance evidence is missing'];
    }
}
