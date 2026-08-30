<?php

namespace App\Services;

use App\Models\Server;
use RuntimeException;
use Symfony\Component\Process\Process;

class Runner
{
    /**
     * The server to run the command on
     */
    protected Server $server;

    /**
     * Sets the server to run the command on
     *
     * @return $this
     */
    public function server(Server $server): Runner
    {
        $this->server = $server;

        return $this;
    }

    /**
     * Create an SSH connection
     *
     *
     * @throws \Exception
     */
    public function create(): ManagedSsh
    {
        $user = 'root';
        $hostname = $this->server->public_ip;
        $privateKey = $this->server->ssh_private_key;

        if (! $hostname) {
            throw new RuntimeException("Server {$this->server->id} does not have a public IP address yet.");
        }

        if (! $privateKey) {
            throw new RuntimeException("Server {$this->server->id} does not have an SSH private key.");
        }

        return ManagedSsh::create($user, $hostname)
            ->usePort(22)
            ->disableStrictHostKeyChecking()
            ->disablePasswordAuthentication()
            ->usePrivateKeyContents($privateKey)
            ->addExtraOption('-o ConnectTimeout='.(int) config('lessbuild.ssh_connect_timeout', 10))
            ->configureProcess(static function (Process $process) {
                $process->setTimeout(max(1, (int) config('lessbuild.ssh_command_timeout', 60)));
            })
            ->onOutput(static function ($type, $line) {
                info($line);
            });
    }
}
