<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Models\Server;
use App\Models\Website;
use InvalidArgumentException;

/**
 * The disk clean-up assistant's scripts: measuring what's clearable on a server, and clearing one kind of it safely.
 * Nothing live is touched: the current release and the websites' kept releases stay, logs from the last two weeks
 * stay, and only unused Docker images go.
 */
final class DiskCleanup
{
    /**
     * The kinds of clearable files, with their names.
     *
     * @var array<string, string>
     */
    public const CATEGORIES = [
        'releases' => 'Old releases',
        'logs' => 'Old logs',
        'caches' => 'Package caches',
        'docker' => 'Unused Docker images',
        'tmp' => 'Old temporary files',
    ];

    /**
     * Build the script that measures each category, printing "category bytes items" lines and the disk's size and
     * free space.
     *
     * @param  Server  $server
     * @return string
     */
    public function scan(Server $server): string
    {
        $releases = $this->releaseSelection($server);

        return <<<BASH
        set -uo pipefail
        sum() { awk '{ bytes += \$1; items++ } END { printf "%d %d\\n", bytes, items }'; }
        printf 'releases '; {$releases} | while IFS= read -r path; do du -sb -- "\$path" 2>/dev/null; done | sum
        printf 'logs '; { find /var/log -type f \\( -name '*.gz' -o -name '*.[0-9]' -o -name '*.old' \\) -printf '%s\\n' 2>/dev/null; find /var/www/*/shared/storage/logs -type f -name '*.log' -mtime +14 -printf '%s\\n' 2>/dev/null; } | sum
        printf 'caches '; { du -sb /var/cache/apt/archives /root/.npm /root/.cache/composer /root/.cache/pip 2>/dev/null | cut -f1; } | sum
        printf 'docker '; if command -v docker >/dev/null 2>&1; then docker image ls --filter dangling=true --format '{{.ID}}' 2>/dev/null | while read -r id; do docker image inspect --format '{{.Size}}' "\$id" 2>/dev/null; done | sum; else echo '0 0'; fi
        printf 'tmp '; find /tmp /var/tmp -xdev -type f -mtime +7 -printf '%s\\n' 2>/dev/null | sum
        printf 'disk '; df -B1 --output=size,avail / | tail -n 1 | awk '{ printf "%d %d\\n", \$1, \$2 }'
        BASH;
    }

    /**
     * Build the script that clears one category.
     *
     * @param  Server  $server
     * @param  string  $category
     * @return string
     */
    public function clean(Server $server, string $category): string
    {
        return match ($category) {
            'releases' => "set -uo pipefail\n".$this->releaseSelection($server).' | while IFS= read -r path; do rm -rf -- "$path"; done',
            'logs' => "set -uo pipefail\nfind /var/log -type f \\( -name '*.gz' -o -name '*.[0-9]' -o -name '*.old' \\) -delete 2>/dev/null\nfind /var/www/*/shared/storage/logs -type f -name '*.log' -mtime +14 -delete 2>/dev/null\njournalctl --vacuum-time=14d >/dev/null 2>&1 || true",
            'caches' => "set -uo pipefail\napt-get clean >/dev/null 2>&1 || true\nrm -rf /root/.npm/_cacache /root/.cache/composer /root/.cache/pip 2>/dev/null || true",
            'docker' => 'command -v docker >/dev/null 2>&1 && docker image prune --force >/dev/null || true',
            'tmp' => 'find /tmp /var/tmp -xdev -type f -mtime +7 -delete 2>/dev/null || true',
            default => throw new InvalidArgumentException('Unknown disk category.'),
        };
    }

    /**
     * Build the command listing each website's old releases: everything in its releases folder beyond the releases it
     * keeps (newest first), never the one that's live.
     *
     * @param  Server  $server
     * @return string
     */
    private function releaseSelection(Server $server): string
    {
        $parts = [];
        foreach (Website::query()->where('server_id', $server->id)->get(['deployment_slug', 'release_retention']) as $website) {
            if (preg_match('/\A[a-z0-9][a-z0-9-]{0,31}\z/', (string) $website->deployment_slug) !== 1) {
                continue;
            }
            $root = escapeshellarg("/var/www/{$website->deployment_slug}");
            $keep = max(2, min(20, (int) $website->release_retention)) + 1;
            $parts[] = "{ LIVE=\"\$(readlink -f {$root}/current 2>/dev/null || true)\"; find {$root}/releases -mindepth 1 -maxdepth 1 -type d -printf '%T@ %p\\n' 2>/dev/null | sort -nr | tail -n +{$keep} | cut -d' ' -f2- | while IFS= read -r release; do [ \"\$(readlink -f \"\$release\")\" = \"\$LIVE\" ] || printf '%s\\n' \"\$release\"; done; }";
        }

        return $parts === [] ? 'true' : '{ '.implode('; ', $parts).'; }';
    }
}
