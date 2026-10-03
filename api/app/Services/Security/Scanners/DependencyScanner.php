<?php

declare(strict_types=1);

namespace App\Services\Security\Scanners;

use App\Contracts\Security\Scanner;
use App\Data\Security\Finding;
use App\Models\Project;
use App\Models\Server;
use App\Models\Website;
use App\Services\Infrastructure\ServerShell;
use App\Services\Security\DependencyCheck;

/**
 * Checks the packages each of a project's websites is running: the composer.lock and package-lock.json in its live
 * release, looked up in the OSV vulnerability database.
 */
final class DependencyScanner implements Scanner
{
    /**
     * Create a new DependencyScanner instance.
     *
     * @param  ServerShell  $shell  Reads the lock files from the servers.
     * @param  DependencyCheck  $check  Finds their vulnerabilities.
     */
    public function __construct(private readonly ServerShell $shell, private readonly DependencyCheck $check) {}

    /**
     * Get the scanner's kind.
     *
     * @return string
     */
    public function kind(): string
    {
        return 'dependencies';
    }

    /**
     * Get the scanner's name.
     *
     * @return string
     */
    public function label(): string
    {
        return __('Vulnerable packages');
    }

    /**
     * Every plan includes dependency scanning.
     *
     * @return string|null
     */
    public function flag(): ?string
    {
        return null;
    }

    /**
     * Check each website in the project that's live on an active server. Websites that can't be read are left out,
     * so their earlier findings stay as they were rather than being resolved.
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
            if ($website->server === null || $website->server->provisioning_status !== Server::STATUS_ACTIVE) {
                continue;
            }
            $files = $this->lockFiles($website, $website->server);
            if ($files === null) {
                continue;
            }
            $results["website:{$website->id}"] = $this->check->findings($files['composer.lock'] ?? null, $files['package-lock.json'] ?? null, $website->name);
        }

        return $results;
    }

    /**
     * Read the website's live lock files, or null when the server couldn't be reached.
     *
     * @param  Website  $website
     * @param  Server  $server  the website's server
     * @return array<string, string>|null
     */
    private function lockFiles(Website $website, Server $server): ?array
    {
        $current = escapeshellarg($website->deploymentPath('current'));
        $result = $this->shell->run($server, <<<BASH
        cd {$current} 2>/dev/null || exit 0
        for file in composer.lock package-lock.json; do
            if [ -f "\$file" ]; then printf '==%s\\n' "\$file"; head -c 8000000 -- "\$file" | base64 -w0; printf '\\n'; fi
        done
        BASH);
        if (! $result->successful()) {
            return null;
        }
        $files = [];
        $name = null;
        foreach (preg_split('/\R/', $result->output) ?: [] as $line) {
            if (str_starts_with($line, '==')) {
                $name = substr($line, 2);
            } elseif ($name !== null && $line !== '') {
                $decoded = base64_decode($line, true);
                if ($decoded !== false) {
                    $files[$name] = $decoded;
                }
                $name = null;
            }
        }

        return $files;
    }
}
