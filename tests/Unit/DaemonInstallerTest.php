<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class DaemonInstallerTest extends TestCase
{
    public function test_installer_configures_a_supervised_database_queue_worker(): void
    {
        $installer = file_get_contents(dirname(__DIR__, 2).'/scripts/install-daemon.sh');

        $this->assertStringContainsString('QUEUE_CONNECTION database', $installer);
        $this->assertStringContainsString('Description=Lessbuild background queue worker', $installer);
        $this->assertStringContainsString('artisan queue:work --queue=default', $installer);
        $this->assertStringContainsString('--tries=3 --timeout=80 --max-time=3600', $installer);
        $this->assertStringContainsString('Environment=APP_DEBUG=false', $installer);
        $this->assertStringContainsString('ExecStartPost=${APP_DIR}/scripts/wait-for-http.sh http://127.0.0.1:8003/ 15 1', $installer);
        $this->assertStringContainsString('TimeoutStartSec=50', $installer);
        $this->assertStringContainsString('systemctl restart "${SERVICE_NAME}.service" "${WORKER_SERVICE_NAME}.service"', $installer);
        $this->assertStringContainsString('Description=Lessbuild consistent SQLite database backup', $installer);
        $this->assertStringContainsString('ExecStart=${PHP_BIN} artisan lessbuild:backup', $installer);
        $this->assertStringContainsString('ExecStart=${PHP_BIN} artisan lessbuild:backups:verify --all', $installer);
        $this->assertStringContainsString('TimeoutStartSec=900', $installer);
        $this->assertStringContainsString('UMask=0027', $installer);
        $this->assertStringContainsString('OnCalendar=daily', $installer);
        $this->assertStringContainsString('RandomizedDelaySec=30m', $installer);
        $this->assertStringContainsString('Persistent=true', $installer);
        $this->assertStringContainsString('systemctl enable --now "${BACKUP_TIMER_NAME}.timer"', $installer);
        $this->assertStringContainsString('if [[ "${DATABASE_CONNECTION}" == "sqlite" ]]', $installer);
        $this->assertStringContainsString('systemctl disable --now "${BACKUP_TIMER_NAME}.timer"', $installer);
        $this->assertStringContainsString('Description=Recover stale Lessbuild deployments', $installer);
        $this->assertStringContainsString('ExecStart=${PHP_BIN} artisan lessbuild:deployments:watchdog', $installer);
        $this->assertStringContainsString('TimeoutStartSec=120', $installer);
        $this->assertStringContainsString('OnCalendar=*-*-* *:*:00', $installer);
        $this->assertStringContainsString('systemctl enable --now "${WATCHDOG_TIMER_NAME}.timer"', $installer);
        $this->assertStringContainsString('Description=Monitor Lessbuild website health', $installer);
        $this->assertStringContainsString('ExecStart=${PHP_BIN} artisan lessbuild:websites:health', $installer);
        $this->assertStringContainsString('TimeoutStartSec=600', $installer);
        $this->assertStringContainsString('OnCalendar=*-*-* *:0/5:00', $installer);
        $this->assertStringContainsString('systemctl enable --now "${HEALTH_TIMER_NAME}.timer"', $installer);

        $syntaxCheck = new Process(['bash', '-n', dirname(__DIR__, 2).'/scripts/install-daemon.sh']);
        $syntaxCheck->run();
        $this->assertTrue($syntaxCheck->isSuccessful(), $syntaxCheck->getErrorOutput());
    }

    public function test_http_readiness_probe_succeeds_and_times_out_deterministically(): void
    {
        $probe = dirname(__DIR__, 2).'/scripts/wait-for-http.sh';

        $success = new Process([$probe, 'file:///etc/hosts', '1', '0']);
        $success->run();
        $this->assertTrue($success->isSuccessful(), $success->getErrorOutput());

        $failure = new Process([$probe, 'http://127.0.0.1:1/', '2', '0']);
        $failure->run();
        $this->assertFalse($failure->isSuccessful());
        $this->assertStringContainsString(
            'HTTP readiness check failed after 2 attempt(s)',
            $failure->getErrorOutput(),
        );

        $syntaxCheck = new Process(['bash', '-n', $probe]);
        $syntaxCheck->run();
        $this->assertTrue($syntaxCheck->isSuccessful(), $syntaxCheck->getErrorOutput());
    }
}
