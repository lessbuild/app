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
        $process->shouldReceive('getOutput')->once()->andReturn("4321\n");
        $ssh = Mockery::mock(Ssh::class);
        $ssh->shouldReceive('execute')
            ->once()
            ->with(Mockery::on(fn ($command) => str_contains($command, 'nohup sudo setsid --')
                && str_contains($command, 'echo $!')
                && ! str_contains($command, '/etc/cron.d/')))
            ->andReturn($process);

        $this->assertSame("4321\n", (new TestPublishable($ssh))->runScript());
    }

    public function test_remote_script_names_are_safe_for_long_resource_names(): void
    {
        $ssh = Mockery::mock(Ssh::class);
        $path = (new TestPublishable($ssh))->makeFile(str_repeat('long-name-', 40));

        $this->assertLessThanOrEqual(200, strlen(basename($path)));
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', basename($path));
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

    public function makeFile(string $name): string
    {
        $this->script = '#!/bin/bash';

        return $this->makeScriptFile($name);
    }
}
