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
        $this->assertStringContainsString('systemctl restart "${SERVICE_NAME}.service" "${WORKER_SERVICE_NAME}.service"', $installer);

        $syntaxCheck = new Process(['bash', '-n', dirname(__DIR__, 2).'/scripts/install-daemon.sh']);
        $syntaxCheck->run();
        $this->assertTrue($syntaxCheck->isSuccessful(), $syntaxCheck->getErrorOutput());
    }
}
