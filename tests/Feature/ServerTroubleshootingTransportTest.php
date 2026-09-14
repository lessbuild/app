<?php

namespace Tests\Feature;

use App\Contracts\ServerTroubleshootingTransport;
use App\Data\ServerTroubleshootingTerminalSize;
use App\Exceptions\ServerTroubleshootingTransportException;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Services\ProcessServerTroubleshootingConnection;
use App\Services\Runner;
use App\Services\SshServerTroubleshootingTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ServerTroubleshootingTransportTest extends TestCase
{
    use RefreshDatabase;

    public function test_transport_contract_resolves_to_the_pinned_ssh_adapter(): void
    {
        $this->assertInstanceOf(
            SshServerTroubleshootingTransport::class,
            app(ServerTroubleshootingTransport::class),
        );
    }

    public function test_local_process_connection_forwards_bounded_input_and_incremental_output(): void
    {
        $input = new InputStream;
        $process = new Process(['bash', '-c', 'printf ready; read value; printf "received:%s\\n" "$value"'], null, null, $input, null);
        $connection = new ProcessServerTroubleshootingConnection($process, $input, 100, 1000);

        try {
            $connection->start();
            $this->assertStringContainsString('ready', $this->waitForOutput($connection, 'ready'));

            $connection->write("hello\n");

            $this->assertStringContainsString('received:hello', $this->waitForOutput($connection, 'received:hello'));
            $this->assertFalse($connection->isRunning());
        } finally {
            $connection->close();
        }

        $this->assertFalse($connection->isRunning());
    }

    public function test_resize_is_serialized_as_a_validated_control_frame(): void
    {
        $input = new InputStream;
        $process = new Process(['bash', '-c', 'IFS= read -r line; printf "resize:%s\\n" "$line"'], null, null, $input, null);
        $connection = new ProcessServerTroubleshootingConnection($process, $input, 100, 1000);

        try {
            $connection->start();
            $connection->resize(new ServerTroubleshootingTerminalSize(120, 40));

            $this->assertStringContainsString(
                'resize:stty rows 40 cols 120',
                $this->waitForOutput($connection, 'resize:stty rows 40 cols 120'),
            );
        } finally {
            $connection->close();
        }
    }

    public function test_terminal_dimensions_are_bounded_before_a_connection_can_start(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ServerTroubleshootingTerminalSize(10, 24);
    }

    public function test_input_frames_are_bounded_and_connection_cleanup_is_idempotent(): void
    {
        $input = new InputStream;
        $process = new Process(['sleep', '20'], null, null, $input, null);
        $released = 0;
        $connection = new ProcessServerTroubleshootingConnection(
            $process,
            $input,
            4,
            1000,
            static function () use (&$released): void {
                $released++;
            },
        );

        $connection->start();
        $this->assertTrue($connection->isRunning());

        try {
            $connection->write('12345');
            $this->fail('An oversized input frame should be rejected.');
        } catch (ServerTroubleshootingTransportException $exception) {
            $this->assertSame('The troubleshooting input frame is too large.', $exception->getMessage());
        } finally {
            $connection->close();
            $connection->close();
        }

        $this->assertFalse($connection->isRunning());
        $this->assertSame(1, $released);
    }

    public function test_output_overflow_closes_the_process_without_retaining_an_unbounded_buffer(): void
    {
        $input = new InputStream;
        $process = new Process(['bash', '-c', 'printf 12345; sleep 20'], null, null, $input, null);
        $connection = new ProcessServerTroubleshootingConnection($process, $input, 100, 4);

        $connection->start();

        try {
            $this->expectOutputOverflow($connection);
        } catch (ServerTroubleshootingTransportException $exception) {
            $this->assertSame('The troubleshooting output frame is too large.', $exception->getMessage());
        }

        $this->assertFalse($connection->isRunning());
    }

    private function expectOutputOverflow(ProcessServerTroubleshootingConnection $connection): void
    {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            try {
                $connection->read();
            } catch (ServerTroubleshootingTransportException $exception) {
                throw $exception;
            }

            usleep(10_000);
        }

        $this->fail('An oversized output frame should close the connection.');
    }

    public function test_ssh_command_uses_pinned_identity_and_no_one_shot_heredoc(): void
    {
        [, $server] = $this->resources();
        $ssh = (new Runner)->server($server)->create(false);

        try {
            $command = $ssh->interactiveCommand(new ServerTroubleshootingTerminalSize(120, 40));
            $rendered = implode(' ', $command);

            $this->assertSame('ssh', $command[0]);
            $this->assertContains('-tt', $command);
            $this->assertContains('BatchMode=yes', $command);
            $this->assertContains('PasswordAuthentication=no', $command);
            $this->assertContains('StrictHostKeyChecking=yes', $command);
            $this->assertContains('EscapeChar=none', $command);
            $this->assertStringContainsString('stty rows 40 cols 120', $rendered);
            $this->assertStringContainsString('trap ', $rendered);
            $this->assertStringContainsString('kill -TERM -- -"$child"', $rendered);
            $this->assertStringContainsString('wait "$child"', $rendered);
            $this->assertStringContainsString('setsid bash --noprofile --norc -i', $rendered);
            $remoteCommand = $command[array_key_last($command)];
            $syntax = new Process(['sh', '-n', '-c', $remoteCommand]);
            $syntax->run();
            $this->assertTrue($syntax->isSuccessful(), $syntax->getErrorOutput());
            $this->assertStringNotContainsString('EOF-SPATIE-SSH', $rendered);
            $this->assertStringNotContainsString('private-key', $rendered);
        } finally {
            $ssh->close();
        }
    }

    public function test_ssh_transport_fails_closed_before_runner_access_for_unpinned_servers(): void
    {
        [, $server] = $this->resources();
        $server->update(['ssh_host_key' => null]);

        $this->expectException(ServerTroubleshootingTransportException::class);
        $this->expectExceptionMessage('A pinned SSH host identity is required for troubleshooting.');

        app(SshServerTroubleshootingTransport::class)->connect(
            $server->fresh(),
            new ServerTroubleshootingTerminalSize,
        );
    }

    /** @return array{0: User, 1: Server} */
    private function resources(): array
    {
        $owner = User::factory()->create();
        $provider = $owner->providers()->create([
            'name' => 'DigitalOcean',
            'description' => 'Cloud provider',
            'provider' => Provider::TYPE_DIGITALOCEAN,
            'token' => 'cloud-secret',
        ]);
        $server = $owner->servers()->create([
            'provider_id' => $provider->id,
            'name' => 'Production',
            'public_ip' => '192.0.2.10',
            'ssh_private_key' => 'private-key',
            'ssh_host_key' => '192.0.2.10 ssh-ed25519 AAAAhost-key',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);

        return [$owner, $server];
    }

    private function waitForOutput(ProcessServerTroubleshootingConnection $connection, string $needle): string
    {
        $output = '';

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $output .= $connection->read();

            if (str_contains($output, $needle)) {
                return $output;
            }

            usleep(10_000);
        }

        $this->fail("Timed out waiting for troubleshooting output: {$needle}");
    }
}
