<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Contracts\Infrastructure\TerminalConnection;
use App\Data\Infrastructure\TerminalSize;
use App\Models\Server;
use Closure;
use RuntimeException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Throwable;

/** Opens an interactive root shell on a server over `ssh -tt`, checking its pinned host key. Swapped for a fake in tests. */
class ServerTerminal
{
    /**
     * Opens interactive shells on servers.
     *
     * @param  Runner  $runner  Builds the SSH client with the server's key and pinned host key.
     */
    public function __construct(private readonly Runner $runner) {}

    /**
     * Opens a root shell with a pseudo-terminal of the given size on an active server with a pinned host key. The
     * connection buffers output until it's read, and closing it stops the shell and removes the temporary key files.
     *
     * @throws RuntimeException with a message safe to show
     */
    public function connect(Server $server, TerminalSize $size): TerminalConnection
    {
        if ($server->provisioning_status !== Server::STATUS_ACTIVE || $server->ssh_host_key === null || $server->public_ip === null || $server->ssh_private_key === null) {
            throw new RuntimeException('The server needs to be active with a pinned SSH host key.');
        }
        $ssh = $this->runner->server($server)->create(false);
        $input = new InputStream;
        $process = $ssh->interactiveProcess($input, $size);
        $process->setTimeout(null);
        $connection = new class($process, $input, $ssh->close(...)) implements TerminalConnection
        {
            private string $buffer = '';

            /** @param Closure(): void $release */
            public function __construct(private readonly Process $process, private readonly InputStream $input, private readonly Closure $release) {}

            public function start(): void
            {
                $this->process->start(function (string $type, string $data): void {
                    $this->buffer .= $data;
                });
            }

            public function write(string $input): void
            {
                $this->input->write($input);
            }

            public function read(): string
            {
                $this->process->isRunning();
                $this->process->clearOutput();
                $this->process->clearErrorOutput();
                [$output, $this->buffer] = [$this->buffer, ''];

                return $output;
            }

            public function isRunning(): bool
            {
                return $this->process->isRunning();
            }

            public function close(): void
            {
                $this->input->close();
                try {
                    if ($this->process->isRunning()) {
                        $this->process->stop(3);
                    }
                } finally {
                    ($this->release)();
                }
            }
        };
        try {
            $connection->start();
        } catch (Throwable $exception) {
            $ssh->close();

            throw new RuntimeException('The SSH connection couldn’t be opened.', previous: $exception);
        }

        return $connection;
    }
}
