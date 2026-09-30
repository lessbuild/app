<?php

declare(strict_types=1);

namespace App\Services\Security\Scanners;

use App\Contracts\Security\Scanner;
use App\Data\Security\Finding;
use App\Models\Project;
use App\Models\Server;
use App\Models\TelemetryEvent;
use App\Models\Website;
use App\Services\Infrastructure\ServerShell;
use App\Support\Security\SecretPatterns;

/**
 * Looks for credentials where they shouldn't be: in the code of each website's live release (not its .env, which is
 * where they belong) and in the project's recent error reports, logs and traces. Secrets are never stored in full.
 */
final class SecretScanner implements Scanner
{
    /**
     * Directories that aren't the project's own code.
     *
     * @var list<string>
     */
    private const SKIPPED = ['vendor', 'node_modules', '.git', 'storage', 'bootstrap/cache', 'public/build'];

    /**
     * The most recent telemetry events checked per scan.
     *
     * @var int
     */
    private const TELEMETRY_LIMIT = 5000;

    /**
     * Create a new SecretScanner instance.
     *
     * @param  ServerShell  $shell  Searches the code on the servers.
     */
    public function __construct(private readonly ServerShell $shell) {}

    /**
     * Get the scanner's kind.
     *
     * @return string
     */
    public function kind(): string
    {
        return 'secrets';
    }

    /**
     * Get the scanner's name.
     *
     * @return string
     */
    public function label(): string
    {
        return __('Leaked secrets');
    }

    /**
     * Secret scanning comes with Pro and above.
     *
     * @return string
     */
    public function flag(): string
    {
        return 'security.secrets';
    }

    /**
     * Search each live website's code, and the project's telemetry from the last week.
     *
     * @param  Project  $project
     * @return array<string, list<Finding>>
     */
    public function scan(Project $project): array
    {
        $results = [];
        $websites = Website::query()->whereIn('environment_id', $project->environments()->select('id'))
            ->where('provisioning_status', Website::STATUS_ACTIVE)->with('server')->get();
        foreach ($websites as $website) {
            if ($website->server !== null && $website->server->provisioning_status === Server::STATUS_ACTIVE) {
                $code = $this->searchCode($website, $website->server);
                if ($code !== null) {
                    $results["code:{$website->id}"] = $code;
                }
            }
        }
        $results['telemetry'] = $this->searchTelemetry($project);

        return $results;
    }

    /**
     * Search a website's live release for credentials, and for .env files committed with the code. Null when the
     * server couldn't be searched.
     *
     * @param  Website  $website
     * @param  Server  $server
     * @return list<Finding>|null
     */
    private function searchCode(Website $website, Server $server): ?array
    {
        $current = escapeshellarg($website->deploymentPath('current'));
        $prune = implode(' -o ', array_map(fn (string $path): string => '-path '.escapeshellarg("./{$path}"), self::SKIPPED));
        $patterns = SecretPatterns::grepArguments();
        $result = $this->shell->run($server, <<<BASH
        cd {$current} 2>/dev/null || exit 0
        find . \\( {$prune} \\) -prune -o -type f -size -1024k -print0 | xargs -0 -r grep -IHnoE {$patterns} 2>/dev/null | head -n 500 || true
        find . -maxdepth 3 \\( {$prune} \\) -prune -o -type f -name '.env*' ! -name '.env.example' ! -name '.env.testing' -print 2>/dev/null | sed 's/^/ENVFILE:/' | head -n 20 || true
        BASH);
        if (! $result->successful()) {
            return null;
        }
        $findings = [];
        foreach (preg_split('/\R/', $result->output) ?: [] as $line) {
            if (str_starts_with($line, 'ENVFILE:')) {
                $path = (string) preg_replace('#^\./#', '', substr($line, 8));
                $findings[] = new Finding("envfile|{$path}", 'high', (string) __('An environment file (:path) is in the code', ['path' => $path]),
                    (string) __('Environment files hold secrets and belong outside the repository.'), $website->name,
                    fix: (string) __('Remove :path from the repository (and its history), add it to .gitignore, and set the values as environment variables in Deploy.', ['path' => $path]));

                continue;
            }
            if (preg_match('/^\.\/(.+?):(\d+):(.*)$/', $line, $parts) !== 1 || str_starts_with($parts[1], '.env')) {
                continue;
            }
            $rule = SecretPatterns::ruleFor($parts[3]);
            if ($rule === null) {
                continue;
            }
            $definition = SecretPatterns::RULES[$rule];
            $findings[] = new Finding("{$rule}|{$parts[1]}|".hash('sha256', $parts[3]), $definition['severity'],
                (string) __(':secret in :path', ['secret' => $definition['name'], 'path' => $parts[1]]),
                (string) __('Line :line: :value', ['line' => $parts[2], 'value' => SecretPatterns::redact($parts[3])]), $website->name,
                fix: (string) __('Revoke this credential with its provider now, then remove it from the code and the repository’s history, and load it from an environment variable instead.'),
                data: ['rule' => $rule, 'path' => $parts[1], 'line' => (int) $parts[2]]);
        }

        return $findings;
    }

    /**
     * Search the project's telemetry from the last week (up to 5,000 recent events) for credentials, such as a key in
     * an exception message or a logged request.
     *
     * @param  Project  $project
     * @return list<Finding>
     */
    private function searchTelemetry(Project $project): array
    {
        $findings = [];
        $events = TelemetryEvent::query()->whereIn('environment_id', $project->environments()->select('id'))
            ->where('occurred_at', '>=', now()->subWeek())->orderByDesc('id')->limit(self::TELEMETRY_LIMIT)
            ->get(['id', 'type', 'name', 'attributes', 'payload', 'occurred_at']);
        foreach ($events as $event) {
            $text = implode(' ', [(string) $event->name, (string) json_encode($event->attributes), (string) json_encode($event->payload)]);
            foreach (SecretPatterns::find(stripslashes($text)) as $secret) {
                $definition = SecretPatterns::RULES[$secret['rule']];
                $findings[] = new Finding("{$secret['rule']}|".hash('sha256', $secret['match']), $definition['severity'],
                    (string) __(':secret in :type data', ['secret' => $definition['name'], 'type' => $event->type]),
                    (string) __(':value, seen in “:name” :time.', ['value' => SecretPatterns::redact($secret['match']), 'name' => str((string) $event->name)->limit(80)->toString(), 'time' => $event->occurred_at->diffForHumans()]),
                    null, route('monitoring.events.show', [$project, $event->id]),
                    (string) __('Revoke this credential, then stop the app sending it: remove it from exception messages and logs before they’re sent.'),
                    ['rule' => $secret['rule'], 'event' => $event->id]);
            }
        }

        return $findings;
    }
}
