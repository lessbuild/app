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
        $command = null;
        $process = Mockery::mock(Process::class);
        $process->shouldReceive('isSuccessful')->once()->andReturnTrue();
        $process->shouldReceive('getOutput')->once()->andReturn("4321\n");
        $ssh = Mockery::mock(Ssh::class);
        $ssh->shouldReceive('execute')
            ->once()
            ->with(Mockery::on(function (string $value) use (&$command): bool {
                $command = $value;

                return str_contains($value, 'nohup sudo setsid --')
                    && str_contains($value, 'process_id=$!')
                    && str_contains($value, "sudo tee '/tmp/test-script.pid'")
                    && str_contains($value, "sudo chmod 600 -- '/tmp/test-script.pid'")
                    && str_contains($value, 'sudo kill -TERM -- "-$process_id"')
                    && str_contains($value, 'echo "$process_id"')
                    && ! str_contains($value, '/etc/cron.d/');
            }))
            ->andReturn($process);

        $this->assertSame("4321\n", (new TestPublishable($ssh))->runScript());
        $syntax = new Process(['bash', '-n']);
        $syntax->setInput($command);
        $syntax->run();
        $this->assertTrue($syntax->isSuccessful(), $syntax->getErrorOutput());
    }

    public function test_remote_script_names_are_safe_for_long_resource_names(): void
    {
        $ssh = Mockery::mock(Ssh::class);
        $path = (new TestPublishable($ssh))->makeFile(str_repeat('long-name-', 40));

        $this->assertLessThanOrEqual(200, strlen(basename($path)));
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', basename($path));
    }

    public function test_explicit_remote_script_identifiers_reject_shell_input(): void
    {
        $ssh = Mockery::mock(Ssh::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('remote script identifier is invalid');

        (new TestPublishable($ssh))->makeFile('Deployment', 'build; touch /tmp/pwned');
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

    public function makeFile(string $name, ?string $fileName = null): string
    {
        $this->script = '#!/bin/bash';

        return $this->makeScriptFile($name, $fileName);
    }
}
