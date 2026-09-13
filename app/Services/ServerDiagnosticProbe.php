<?php

namespace App\Services;

use App\Data\OperationalDiagnosticCheck;
use App\Data\OperationalDiagnosticReport;
use App\Enums\OperationalDiagnosticCategory;
use App\Enums\ServerDiagnosticFailureStage;
use App\Exceptions\ServerDiagnosticException;
use App\Models\Server;
use Throwable;

class ServerDiagnosticProbe
{
    /**
     * Keep the remote probe fixed and independent of user, database and
     * environment-file values.
     */
    public const string SCRIPT = <<<'BASH'
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

    private const int DISK_WARNING_PERCENT = 90;

    private const int MEMORY_WARNING_PERCENT = 90;

    public function __construct(
        private readonly Runner $runner,
        private readonly ServerDiagnosticOutputParser $parser,
    ) {}

    /**
     * Execute and translate the fixed read-only host probe into typed checks.
     *
     * @throws ServerDiagnosticException If the server cannot safely be probed.
     */
    public function handle(Server $server): OperationalDiagnosticReport
    {
        if ($server->provisioning_status !== Server::STATUS_ACTIVE) {
            throw new ServerDiagnosticException(
                ServerDiagnosticFailureStage::ServerState,
                'Diagnostics are available only for active servers.',
            );
        }

        if (! $server->ssh_host_key) {
            throw new ServerDiagnosticException(
                ServerDiagnosticFailureStage::HostIdentity,
                'A pinned SSH host identity is required before diagnostics can run.',
            );
        }

        if (! $server->public_ip || ! $server->ssh_private_key) {
            throw new ServerDiagnosticException(
                ServerDiagnosticFailureStage::Transport,
                'The server does not have the connection details required for diagnostics.',
            );
        }

        try {
            $process = $this->runner->server($server)->create(false)->execute(self::SCRIPT);
        } catch (Throwable) {
            throw new ServerDiagnosticException(
                ServerDiagnosticFailureStage::Transport,
                'Unable to connect to the server for diagnostics.',
            );
        }

        if (! $process->isSuccessful()) {
            throw new ServerDiagnosticException(
                ServerDiagnosticFailureStage::Transport,
                'The server diagnostic connection failed.',
            );
        }

        try {
            $values = $this->parser->parse($process->getOutput());
        } catch (Throwable) {
            throw new ServerDiagnosticException(
                ServerDiagnosticFailureStage::Response,
                'The server returned an invalid diagnostic response.',
            );
        }

        return new OperationalDiagnosticReport([
            $this->check('SSH host identity', OperationalDiagnosticCategory::Connectivity, true, 'Pinned identity used'),
            $this->check('SSH transport', OperationalDiagnosticCategory::Connectivity, true, 'Connected successfully'),
            $this->check(
                'Root access',
                OperationalDiagnosticCategory::Runtime,
                $values['uid'] === 0,
                $values['uid'] === 0 ? 'Direct root access available' : 'Direct root access is unavailable',
            ),
            $this->check(
                'Server architecture',
                OperationalDiagnosticCategory::Runtime,
                in_array($values['architecture'], ['x86_64', 'aarch64', 'arm64'], true),
                $values['architecture'],
            ),
            $this->check(
                'PHP runtime',
                OperationalDiagnosticCategory::Runtime,
                $values['php_version'] !== 'missing',
                $values['php_version'] === 'missing' ? 'PHP is unavailable' : "PHP {$values['php_version']} available",
            ),
            $this->check(
                'Application storage path',
                OperationalDiagnosticCategory::Storage,
                $values['storage_path'] === 'present' && $values['storage_writable'] === 'yes',
                $values['storage_path'] !== 'present'
                    ? 'The application storage path is missing'
                    : ($values['storage_writable'] === 'yes' ? 'Application path is writable' : 'Application path is not writable'),
            ),
            $this->check(
                'Disk utilization',
                OperationalDiagnosticCategory::Storage,
                $values['disk_percent'] <= self::DISK_WARNING_PERCENT,
                "Used {$values['disk_percent']}%",
            ),
            $this->check(
                'Memory utilization',
                OperationalDiagnosticCategory::Process,
                $values['memory_percent'] <= self::MEMORY_WARNING_PERCENT,
                "Used {$values['memory_percent']}%",
            ),
            $this->check(
                'Process health',
                OperationalDiagnosticCategory::Process,
                $values['process_count'] > 0,
                "{$values['process_count']} processes observed at load ".number_format($values['load_1m'], 2, '.', ''),
            ),
        ]);
    }

    private function check(
        string $name,
        OperationalDiagnosticCategory $category,
        bool $passed,
        string $detail,
    ): OperationalDiagnosticCheck {
        return new OperationalDiagnosticCheck($name, $category, $passed, $detail);
    }
}
