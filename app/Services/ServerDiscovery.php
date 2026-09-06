<?php

namespace App\Services;

use App\Models\Server;
use RuntimeException;

class ServerDiscovery
{
    public function __construct(private readonly SshHostIdentity $hostIdentity, private readonly Runner $runner) {}

    /** @param array{public_ip:string,ssh_port:int,ssh_private_key:string} $configuration
     *  @return array<string,mixed>
     */
    public function inspect(array $configuration): array
    {
        $identity = $this->hostIdentity->scan($configuration['public_ip'], $configuration['ssh_port']);
        $server = new Server([
            'public_ip' => $configuration['public_ip'], 'ssh_port' => $configuration['ssh_port'],
            'ssh_private_key' => $configuration['ssh_private_key'], 'ssh_host_key' => $identity['known_host'],
        ]);
        $result = $this->runner->server($server)->create(false)->execute(<<<'BASH'
set -eu
. /etc/os-release 2>/dev/null || true
printf 'uid=%s\n' "$(id -u)"
printf 'os_id=%s\n' "${ID:-unknown}"
printf 'os_version=%s\n' "${VERSION_ID:-unknown}"
printf 'architecture=%s\n' "$(uname -m)"
printf 'hostname=%s\n' "$(hostname)"
printf 'memory_mb=%s\n' "$(awk '/MemTotal/{printf "%d", $2/1024}' /proc/meminfo)"
printf 'disk_free_mb=%s\n' "$(df -Pm / | awk 'NR==2{print $4}')"
printf 'buildpusher_managed=%s\n' "$([ -f /etc/buildpusher/managed ] && echo yes || echo no)"
for service in caddy nginx apache2 mysql mariadb postgresql redis-server docker php node supervisor; do
  if command -v "$service" >/dev/null 2>&1 || systemctl list-unit-files "$service.service" >/dev/null 2>&1; then printf 'service_%s=yes\n' "$service"; fi
done
BASH);
        if (! $result->isSuccessful()) {
            throw new RuntimeException('Read-only SSH inspection failed: '.trim($result->getErrorOutput() ?: $result->getOutput()));
        }

        $facts = [];
        foreach (preg_split('/\R/', trim($result->getOutput())) ?: [] as $line) {
            if (preg_match('/\A([a-z0-9_]+)=(.{0,500})\z/', $line, $match)) $facts[$match[1]] = $match[2];
        }
        if (($facts['uid'] ?? null) !== '0') throw new RuntimeException('BuildPusher requires direct root SSH access for unattended provisioning.');
        if (($facts['os_id'] ?? null) !== 'ubuntu') throw new RuntimeException('Only Ubuntu servers are supported for safe import.');
        if (! in_array($facts['os_version'] ?? '', config('lessbuild.supported_ubuntu_versions', []), true)) throw new RuntimeException('This Ubuntu release is not supported. Use a currently supported LTS release.');
        if (! in_array($facts['architecture'] ?? '', ['x86_64', 'aarch64', 'arm64'], true)) throw new RuntimeException('This server architecture is not supported.');

        $services = collect($facts)->filter(fn ($value, $key) => str_starts_with($key, 'service_') && $value === 'yes')->keys()->map(fn ($key) => str_replace('service_', '', $key))->values()->all();
        $warnings = [];
        if (($facts['buildpusher_managed'] ?? 'no') === 'yes') $warnings[] = 'This host already contains a BuildPusher management marker.';
        if ($services !== []) $warnings[] = 'Existing services may be reconfigured or restarted during provisioning.';
        if ((int) ($facts['disk_free_mb'] ?? 0) < 4096) $warnings[] = 'Less than 4 GB of disk space is available.';
        if ((int) ($facts['memory_mb'] ?? 0) < 1024) $warnings[] = 'Less than 1 GB of memory is available.';

        return [...$identity, ...$facts, 'services' => $services, 'warnings' => $warnings, 'inspected_at' => now()->toIso8601String()];
    }
}
