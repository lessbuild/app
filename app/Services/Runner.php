<?php

namespace App\Services;

use App\Models\Server;
use RuntimeException;
use Spatie\Ssh\Ssh;
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
     * Sets the server to run the command on
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
     * @return \Spatie\Ssh\Ssh
     *
     * @throws \Exception
     */
    public function create(): Ssh
    {
        $user = 'root';
        $hostname = $this->server->public_ip;
        $privateKey = config('lessbuild.private_key');

        if (! $hostname) {
            throw new RuntimeException("Server {$this->server->id} does not have a public IP address yet.");
        }

        if (! $privateKey || ! is_readable($privateKey)) {
            throw new RuntimeException('SSH_PRIVATE_KEY must point to a readable private key.');
        }

        return Ssh::create($user, $hostname)
            ->usePort(22)
            ->disableStrictHostKeyChecking()
            ->disablePasswordAuthentication()
            ->usePrivateKey($privateKey)
            ->addExtraOption('-o ConnectTimeout='.(int) config('lessbuild.ssh_connect_timeout', 10))
            ->configureProcess(static function (Process $process) {
                $process->setTimeout(max(1, (int) config('lessbuild.ssh_command_timeout', 60)));
            })
            ->onOutput(static function ($type, $line) {
                info($line);
            });
    }
}
