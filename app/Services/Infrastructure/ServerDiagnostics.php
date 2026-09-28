<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Models\Server;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Checks a server's SSH connection, root access, PHP, the application path, disk, memory and processes with one read-only
 * script. Failures carry a stage (server_state, host_identity, transport, response) in the exception code's message prefix.
 */
final class ServerDiagnostics
{
    public const SCRIPT = <<<'BASH'
set -eu
printf 'bp_diag_version=1\n'
printf 'uid=%s\n' "$(id -u)"
printf 'architecture=%s\n' "$(uname -m)"
if command -v php >/dev/null 2>&1; then
    printf 'php_version=%s\n' "$(php -d display_errors=0 -r 'printf("%s", PHP_VERSION);')"
else
    printf 'php_version=missing\n'
fi
if [ -d /var/www ]; then
    printf 'storage_path=present\n'
    [ -w /var/www ] && printf 'storage_writable=yes\n' || printf 'storage_writable=no\n'
else
    printf 'storage_path=missing\n'
    printf 'storage_writable=no\n'
fi
disk_percent="$(df -P /var/www 2>/dev/null | awk 'NR==2 {gsub(/%/, "", $5); print $5}' || true)"
printf 'disk_percent=%s\n' "${disk_percent:-100}"
printf 'load_1m=%s\n' "$(awk '{print $1}' /proc/loadavg)"
printf 'memory_percent=%s\n' "$(awk '/MemTotal:/ {total=$2} /MemAvailable:/ {available=$2} END {if (total > 0) printf "%.0f", ((total-available)/total)*100; else print 100}' /proc/meminfo)"
process_count="$(ps -e --no-headers 2>/dev/null | wc -l || true)"
printf 'process_count=%s\n' "${process_count:-0}"
BASH;

    private const KEYS = ['bp_diag_version', 'uid', 'architecture', 'php_version', 'storage_path', 'storage_writable', 'disk_percent', 'load_1m', 'memory_percent', 'process_count'];

    /**
     * Diagnoses servers.
     *
     * @param  ServerShell  $shell  Runs the read-only diagnostic script.
     */
    public function __construct(private readonly ServerShell $shell) {}

    /**
     * Runs the diagnostic and turns its answers into checks. Failures are thrown with their stage as a message prefix,
     * so the job can record where it failed.
     *
     * @param  Server  $server
     * @return list<array{name: string, category: string, passed: bool, detail: string}>
     */
    public function run(Server $server): array
    {
        $readiness = $this->readiness($server);
        if ($readiness !== null) {
            throw new RuntimeException($readiness[0].': '.$readiness[1]);
        }
        try {
            $result = $this->shell->run($server, self::SCRIPT);
        } catch (Throwable) {
            throw new RuntimeException('transport: Unable to connect to the server for diagnostics.');
        }
        if (! $result->successful()) {
            throw new RuntimeException('transport: The diagnostic connection failed.');
        }
        try {
            $v = $this->parse($result->output);
        } catch (Throwable) {
            throw new RuntimeException('response: The server returned an invalid diagnostic response.');
        }

        return [
            $this->check('SSH host identity', 'connectivity', true, 'Pinned identity used'),
            $this->check('SSH transport', 'connectivity', true, 'Connected successfully'),
            $this->check('Root access', 'runtime', $v['uid'] === 0, $v['uid'] === 0 ? 'Direct root access available' : 'Direct root access is unavailable'),
            $this->check('Server architecture', 'runtime', in_array($v['architecture'], ['x86_64', 'aarch64', 'arm64'], true), $v['architecture']),
            $this->check('PHP runtime', 'runtime', $v['php_version'] !== 'missing', $v['php_version'] === 'missing' ? 'PHP is unavailable' : "PHP {$v['php_version']} available"),
            $this->check('Application storage path', 'storage', $v['storage_path'] === 'present' && $v['storage_writable'] === 'yes',
                $v['storage_path'] !== 'present' ? 'The application storage path is missing' : ($v['storage_writable'] === 'yes' ? 'Application path is writable' : 'Application path is not writable')),
            $this->check('Disk utilization', 'storage', $v['disk_percent'] <= 90, "Used {$v['disk_percent']}%"),
            $this->check('Memory utilization', 'process', $v['memory_percent'] <= 90, "Used {$v['memory_percent']}%"),
            $this->check('Process health', 'process', $v['process_count'] > 0, "{$v['process_count']} processes observed at load ".number_format($v['load_1m'], 2, '.', '')),
        ];
    }

    /**
     * Why diagnostics can't run on this server yet, as [stage, message], or null.
     *
     * @param  Server  $server
     * @return array{string, string}|null
     */
    public function readiness(Server $server): ?array
    {
        return match (true) {
            $server->provisioning_status !== Server::STATUS_ACTIVE => ['server_state', 'Diagnostics are available only for active servers.'],
            $server->ssh_host_key === null || $server->ssh_host_key === '' => ['host_identity', 'A pinned SSH host identity is required before diagnostics can run.'],
            $server->public_ip === null || $server->ssh_private_key === null => ['transport', 'The server doesn’t have the connection details diagnostics need.'],
            default => null,
        };
    }

    /**
     * One check as the diagnostics tab shows it.
     *
     * @param  string  $name
     * @param  string  $category
     * @param  bool  $passed
     * @param  string  $detail
     * @return array{name: string, category: string, passed: bool, detail: string}
     */
    private function check(string $name, string $category, bool $passed, string $detail): array
    {
        return ['name' => $name, 'category' => $category, 'passed' => $passed, 'detail' => $detail];
    }

    /**
     * Reads the script's `key=value` lines strictly: every expected key exactly once, each value in its expected form,
     * and a bounded size. Anything else is treated as an invalid response rather than guessed at.
     *
     * @param  string  $output
     * @return array{uid: int, architecture: string, php_version: string, storage_path: string, storage_writable: string, disk_percent: int, load_1m: float, memory_percent: int, process_count: int}
     */
    private function parse(string $output): array
    {
        if (strlen($output) > max(1, (int) config('infrastructure.server_diagnostic_output_max_characters', 16384))) {
            throw new InvalidArgumentException('The diagnostic response was too large.');
        }
        $values = [];
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            if (preg_match('/\A([a-z0-9_]+)=([^\r\n]{1,128})\z/D', $line, $match) !== 1 || ! in_array($match[1], self::KEYS, true) || array_key_exists($match[1], $values)) {
                throw new InvalidArgumentException('The diagnostic response was invalid.');
            }
            $values[$match[1]] = $match[2];
        }
        foreach (self::KEYS as $key) {
            if (! array_key_exists($key, $values)) {
                throw new InvalidArgumentException('The diagnostic response was incomplete.');
            }
        }
        $valid = $values['bp_diag_version'] === '1'
            && preg_match('/\A\d{1,6}\z/D', $values['uid']) === 1
            && preg_match('/\A[A-Za-z0-9_.-]{1,32}\z/D', $values['architecture']) === 1
            && preg_match('/\A(?:missing|\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.-]+)?)\z/D', $values['php_version']) === 1
            && in_array($values['storage_path'], ['present', 'missing'], true)
            && in_array($values['storage_writable'], ['yes', 'no'], true)
            && preg_match('/\A\d{1,3}\z/D', $values['disk_percent']) === 1 && (int) $values['disk_percent'] <= 100
            && preg_match('/\A\d{1,6}(?:\.\d{1,2})?\z/D', $values['load_1m']) === 1
            && preg_match('/\A\d{1,3}\z/D', $values['memory_percent']) === 1 && (int) $values['memory_percent'] <= 100
            && preg_match('/\A\d{1,9}\z/D', $values['process_count']) === 1;
        if (! $valid) {
            throw new InvalidArgumentException('The diagnostic response contained an invalid value.');
        }

        return [
            'uid' => (int) $values['uid'], 'architecture' => $values['architecture'], 'php_version' => $values['php_version'],
            'storage_path' => $values['storage_path'], 'storage_writable' => $values['storage_writable'], 'disk_percent' => (int) $values['disk_percent'],
            'load_1m' => (float) $values['load_1m'], 'memory_percent' => (int) $values['memory_percent'], 'process_count' => (int) $values['process_count'],
        ];
    }
}
