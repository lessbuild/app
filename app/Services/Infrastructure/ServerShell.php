<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Data\Infrastructure\ShellResult;
use App\Models\Server;

/** Runs a command on a server as root over SSH, checking its pinned host key. Swapped for a fake in tests. */
class ServerShell
{
    /**
     * Runs commands on servers.
     *
     * @param  Runner  $runner  Builds the SSH client.
     */
    public function __construct(private readonly Runner $runner) {}

    /**
     * Runs a command as root and returns its output and exit code. Output is only logged when asked, since it can
     * contain secrets.
     *
     * @param  Server  $server
     * @param  string  $command
     * @param  bool  $logOutput
     * @return ShellResult
     */
    public function run(Server $server, string $command, bool $logOutput = false): ShellResult
    {
        $process = $this->runner->server($server)->create($logOutput)->execute($command);

        return new ShellResult($process->getOutput(), $process->getErrorOutput(), $process->getExitCode());
    }
}
