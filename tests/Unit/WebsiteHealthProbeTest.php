<?php

namespace Tests\Unit;

use App\Models\Server;
use App\Models\Website;
use App\Services\ManagedSsh;
use App\Services\Runner;
use App\Services\WebsiteHealthProbe;
use Mockery;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class WebsiteHealthProbeTest extends TestCase
{
    public function test_probe_returns_bounded_success_metrics_without_persisting_state(): void
    {
        $command = null;
        $probe = $this->probe(true, '200 0.125000', $command);

        $result = $probe->probe($this->website());

        $this->assertTrue($result->successful);
        $this->assertNull($result->error);
        $this->assertSame(200, $result->httpStatus);
        $this->assertSame(125, $result->durationMs);
        $this->assertStringContainsString("'http://app.example.com/health/ready'", $command);
        $this->assertStringContainsString('--retry 1 --retry-delay 1 --retry-all-errors', $command);
    }

    public function test_probe_returns_bounded_failure_metrics(): void
    {
        $command = null;
        $probe = $this->probe(false, '503 0.250000', $command, 'Remote response '.str_repeat('x', 600));

        $result = $probe->probe($this->website());

        $this->assertFalse($result->successful);
        $this->assertSame(503, $result->httpStatus);
        $this->assertSame(250, $result->durationMs);
        $this->assertSame(500, strlen((string) $result->error));
    }

    public function test_probe_sanitizes_runner_failures(): void
    {
        $runner = Mockery::mock(Runner::class);
        $runner->shouldReceive('server')->once()->andThrow(new RuntimeException('Connection unavailable'));

        $result = (new WebsiteHealthProbe($runner))->probe($this->website());

        $this->assertFalse($result->successful);
        $this->assertSame('Connection unavailable', $result->error);
        $this->assertNull($result->httpStatus);
        $this->assertNull($result->durationMs);
    }

    private function probe(
        bool $successful,
        string $output,
        ?string &$command,
        string $error = '',
    ): WebsiteHealthProbe {
        $process = Mockery::mock(Process::class);
        $process->shouldReceive('getOutput')->once()->andReturn($output);
        $process->shouldReceive('isSuccessful')->once()->andReturn($successful);
        if (! $successful) {
            $process->shouldReceive('getErrorOutput')->once()->andReturn($error);
        }

        $ssh = Mockery::mock(ManagedSsh::class);
        $ssh->shouldReceive('execute')->once()->withArgs(function (string $value) use (&$command): bool {
            $command = $value;

            return true;
        })->andReturn($process);
        $runner = Mockery::mock(Runner::class);
        $runner->shouldReceive('server')->once()->andReturnSelf();
        $runner->shouldReceive('create')->once()->andReturn($ssh);

        return new WebsiteHealthProbe($runner);
    }

    private function website(): Website
    {
        $website = new Website([
            'url' => 'app.example.com',
            'health_check_path' => '/health/ready',
        ]);
        $website->setRelation('server', new Server);

        return $website;
    }
}
