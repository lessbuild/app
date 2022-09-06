<?php

namespace App\Services;

use App\Models\Server;
use Spatie\Ssh\Ssh;

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
     * @return \Spatie\Ssh\Ssh|null
     *
     * @throws \Exception
     */
    public function create(): ?Ssh
    {
        $user = 'root';
        $hostname = $this->server->public_ip;

        if(!isset($hostname)) return null;

        return Ssh::create($user, $hostname)
            ->usePort(22)
            ->disableStrictHostKeyChecking()
            ->usePrivateKey(config('lessbuild.private_key'))
            ->onOutput(static function ($type, $line) {
                info($line);
            });
    }
}
