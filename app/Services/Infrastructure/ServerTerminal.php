<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Contracts\Infrastructure\TerminalConnection;
use App\Data\Infrastructure\TerminalSize;
use App\Models\Server;
use RuntimeException;
use Symfony\Component\Process\InputStream;
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
     * @param  Server  $server
     * @param  TerminalSize  $size
     * @return TerminalConnection
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
        $connection = new SshTerminalConnection($process, $input, $ssh->close(...));
        try {
            $connection->start();
        } catch (Throwable $exception) {
            $ssh->close();

            throw new RuntimeException('The SSH connection couldn’t be opened.', previous: $exception);
        }

        return $connection;
    }
}
