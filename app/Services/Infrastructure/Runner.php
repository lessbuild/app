<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Models\Server;
use Exception;
use RuntimeException;
use Symfony\Component\Process\Process;

class Runner
{
    /**
     * The server to run the command on
     *
     * @var Server
     */
    protected Server $server;

    /**
     * Set the server to run the command on
     *
     * @param  Server  $server
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
     * @param  bool  $logOutput
     * @return ManagedSsh
     *
     * @throws Exception
     */
    public function create(bool $logOutput = true): ManagedSsh
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

        $ssh = new ManagedSsh($user, $hostname);
        $ssh->usePort($this->server->ssh_port ?: 22);
        $ssh->usePrivateKeyContents($privateKey);
        $ssh->disablePasswordAuthentication()
            ->addExtraOption('-o ConnectTimeout='.(int) config('infrastructure.ssh_connect_timeout', 10))
            ->configureProcess(static function (Process $process) {
                $process->setTimeout(max(1, (int) config('infrastructure.ssh_command_timeout', 60)));
            });
        if ($this->server->ssh_host_key) {
            $ssh->useKnownHost($this->server->ssh_host_key);
        } else {
            $ssh->disableStrictHostKeyChecking();
        }
        if ($logOutput) {
            $ssh->onOutput(static function ($type, $line) {
                info($line);
            });
        }

        return $ssh;
    }
}
