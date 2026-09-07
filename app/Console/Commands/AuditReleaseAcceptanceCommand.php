<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\Provider;
use App\Services\ReleaseAcceptance;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

class AuditReleaseAcceptanceCommand extends Command
{
    protected $signature = 'buildpusher:acceptance:audit
        {project : Project ID}
        {--provider= : Require evidence from digitalocean, hetzner, or vultr}
        {--since= : Only accept lifecycle evidence recorded at or after this ISO-8601 time}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Verify recorded evidence for the real-provider release acceptance lifecycle';

    /**
     * Validate the project option and print release acceptance checks from retained lifecycle evidence.
     *
     * @param  ReleaseAcceptance  $acceptance  Evaluator of persisted release lifecycle acceptance evidence.
     * @return int SUCCESS when every acceptance check passes, FAILURE when evidence fails, or INVALID for an invalid project.
     */
    public function handle(ReleaseAcceptance $acceptance): int
    {
        $projectId = filter_var($this->argument('project'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $project = $projectId ? Project::query()->find($projectId) : null;
        if (! $project) {
            $this->error('Project must identify an existing project.');

            return self::INVALID;
        }

        $provider = $this->option('provider');
        if (! filled($provider) || ! in_array($provider, Provider::SERVER_TYPES, true)) {
            $this->error('Provider must be one of: '.implode(', ', Provider::SERVER_TYPES).'.');

            return self::INVALID;
        }

        try {
            $sinceValue = $this->option('since');
            if (! filled($sinceValue) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', (string) $sinceValue) !== 1) {
                throw new \InvalidArgumentException;
            }
            $since = Carbon::parse((string) $sinceValue)->utc();
            $parts = date_parse((string) $sinceValue);
            if ($parts['warning_count'] > 0 || $parts['error_count'] > 0) {
                throw new \InvalidArgumentException;
            }
        } catch (Throwable) {
            $this->error('Since must be a valid ISO-8601 date and time.');

            return self::INVALID;
        }

        $checks = $acceptance->audit($project, $since, $provider);
        $passed = collect($checks)->every('passed');
        $externalChecks = [
            'Verify restored application data against the pre-backup fixture.',
            'Confirm disposable resources were removed in the cloud provider account.',
            'Confirm evidence came from a real drill; database records alone do not establish this.',
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'status' => $passed ? 'passed' : 'incomplete',
                'project_id' => $project->id,
                'provider' => $provider,
                'since' => $since?->toIso8601String(),
                'checks' => $checks,
                'scope' => 'recorded_lifecycle_evidence',
                'external_verification_required' => $externalChecks,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->table(['Stage', 'Status', 'Evidence'], array_map(fn (array $check): array => [
                $check['name'], $check['passed'] ? 'PASS' : 'MISSING', $check['detail'],
            ], $checks));
            $this->line('This result covers recorded lifecycle evidence only.');
            foreach ($externalChecks as $check) {
                $this->line($check);
            }
        }

        return $passed ? self::SUCCESS : self::FAILURE;
    }
}
