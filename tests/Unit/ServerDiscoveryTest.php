<?php

namespace Tests\Unit;

use App\Models\Server;
use App\Services\ManagedSsh;
use App\Services\Runner;
use App\Services\ServerDiscovery;
use App\Services\SshHostIdentity;
use Mockery;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ServerDiscoveryTest extends TestCase
{
    public function test_discovery_pins_identity_and_reports_existing_services_and_capacity(): void
    {
        [$discovery, $capture] = $this->discovery("uid=0\nos_id=ubuntu\nos_version=24.04\narchitecture=x86_64\nhostname=legacy\nmemory_mb=512\ndisk_free_mb=3000\nbuildpusher_managed=no\nservice_nginx=yes\nservice_php=yes\n");
        $report = $discovery->inspect($this->configuration());

        $this->assertSame('SHA256:pinned', $report['fingerprint']);
        $this->assertSame(['nginx', 'php'], $report['services']);
        $this->assertCount(3, $report['warnings']);
        $this->assertStringContainsString('. /etc/os-release', $capture->command);
        $this->assertStringNotContainsString('apt ', $capture->command);
        $this->assertStringNotContainsString('sudo ', $capture->command);
    }

    public function test_discovery_rejects_non_root_non_ubuntu_and_unsupported_architecture(): void
    {
        foreach ([
            "uid=1000\nos_id=ubuntu\nos_version=24.04\narchitecture=x86_64\n",
            "uid=0\nos_id=debian\nos_version=12\narchitecture=x86_64\n",
            "uid=0\nos_id=ubuntu\nos_version=24.04\narchitecture=i386\n",
        ] as $output) {
            [$discovery] = $this->discovery($output);
            try { $discovery->inspect($this->configuration()); $this->fail('Unsafe host was accepted.'); }
            catch (RuntimeException) { $this->addToAssertionCount(1); }
        }
    }

    private function discovery(string $output): array
    {
        $identity = Mockery::mock(SshHostIdentity::class);
        $identity->shouldReceive('scan')->once()->with('203.0.113.10', 2222)->andReturn([
            'known_host' => '[203.0.113.10]:2222 ssh-ed25519 AAAA', 'fingerprint' => 'SHA256:pinned', 'algorithm' => 'ssh-ed25519',
        ]);
        $process = Mockery::mock(Process::class);
        $process->shouldReceive('isSuccessful')->once()->andReturnTrue();
        $process->shouldReceive('getOutput')->andReturn($output);
        $ssh = Mockery::mock(ManagedSsh::class);
        $capture = (object) ['command' => null];
        $ssh->shouldReceive('execute')->once()->with(Mockery::on(function ($value) use ($capture) { $capture->command = $value; return true; }))->andReturn($process);
        $runner = Mockery::mock(Runner::class);
        $runner->shouldReceive('server')->once()->with(Mockery::on(fn (Server $server) => $server->ssh_host_key !== null))->andReturnSelf();
        $runner->shouldReceive('create')->once()->with(false)->andReturn($ssh);

        return [new ServerDiscovery($identity, $runner), $capture];
    }

    private function configuration(): array
    {
        return ['public_ip' => '203.0.113.10', 'ssh_port' => 2222, 'ssh_private_key' => 'secret'];
    }
}
