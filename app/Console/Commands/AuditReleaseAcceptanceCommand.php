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

    public function handle(ReleaseAcceptance $acceptance): int
    {
        $project = Project::query()->findOrFail((int) $this->argument('project'));
        $provider = $this->option('provider');
        if (filled($provider) && ! in_array($provider, Provider::SERVER_TYPES, true)) {
            $this->error('Provider must be one of: '.implode(', ', Provider::SERVER_TYPES).'.');

            return self::INVALID;
        }

        try {
            $sinceValue = $this->option('since');
            if (filled($sinceValue) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', (string) $sinceValue) !== 1) {
                throw new \InvalidArgumentException;
            }
            $since = filled($sinceValue) ? Carbon::parse((string) $sinceValue)->utc() : null;
        } catch (Throwable) {
            $this->error('Since must be a valid ISO-8601 date and time.');

            return self::INVALID;
        }

        $checks = $acceptance->audit($project, $since, $provider ?: null);
        $passed = collect($checks)->every('passed');

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'status' => $passed ? 'passed' : 'incomplete',
                'project_id' => $project->id,
                'provider' => $provider ?: null,
                'since' => $since?->toIso8601String(),
                'checks' => $checks,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->table(['Stage', 'Status', 'Evidence'], array_map(fn (array $check): array => [
                $check['name'], $check['passed'] ? 'PASS' : 'MISSING', $check['detail'],
            ], $checks));
        }

        return $passed ? self::SUCCESS : self::FAILURE;
    }
}
