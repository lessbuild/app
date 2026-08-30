<?php

namespace Tests\Unit;

use App\Abstracts\Publishable;
use Mockery;
use RuntimeException;
use Spatie\Ssh\Ssh;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PublishableTest extends TestCase
{
    public function test_upload_retries_are_bounded(): void
    {
        config(['lessbuild.ssh_upload_attempts' => 3, 'lessbuild.ssh_retry_delay_ms' => 0]);
        $failure = Mockery::mock(Process::class);
        $failure->shouldReceive('isSuccessful')->andReturnFalse();
        $failure->shouldReceive('getErrorOutput')->andReturn('Connection refused');
        $ssh = Mockery::mock(Ssh::class);
        $ssh->shouldReceive('upload')->times(3)->andReturn($failure);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('after 3 attempts');

        (new TestPublishable($ssh))->uploadScript();
    }

    public function test_remote_script_is_started_once_in_the_background(): void
    {
        $process = Mockery::mock(Process::class);
        $process->shouldReceive('isSuccessful')->once()->andReturnTrue();
        $process->shouldReceive('getOutput')->once()->andReturn('started');
        $ssh = Mockery::mock(Ssh::class);
        $ssh->shouldReceive('execute')
            ->once()
            ->with(Mockery::on(fn ($command) => str_contains($command, 'nohup') && ! str_contains($command, '/etc/cron.d/')))
            ->andReturn($process);

        $this->assertSame('started', (new TestPublishable($ssh))->runScript());
    }
}

class TestPublishable extends Publishable
{
    public function __construct(Ssh $runner)
    {
        $this->runner = $runner;
        $this->file = '/tmp/test-script';
        $this->fileName = 'test-script';
    }

    public function uploadScript(): bool
    {
        return $this->upload();
    }

    public function runScript(): string
    {
        return $this->run();
    }
}
