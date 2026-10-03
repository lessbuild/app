<?php

declare(strict_types=1);

namespace App\Jobs\Deploy;

use App\Models\Environment;
use App\Models\Server;
use App\Services\Deploy\EnvironmentRecipes;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Runs an environment's recipes on a server by themselves, waiting for the server to be free of other commands. */
final class RunEnvironmentRecipes implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Tries while the server is busy: one a minute for ten minutes.
     *
     * @var int
     */
    public int $tries = 10;

    /**
     * Create a new RunEnvironmentRecipes instance.
     *
     * Runs one environment's recipes on one server.
     *
     * @param  string  $environmentId  The environment.
     * @param  int  $serverId  The server a website of the environment is on.
     */
    public function __construct(public readonly string $environmentId, public readonly int $serverId) {}

    /**
     * Queue the recipes as a command on the server, or try again in a minute while another command runs there.
     *
     * @param  EnvironmentRecipes  $recipes
     * @return void
     */
    public function handle(EnvironmentRecipes $recipes): void
    {
        $environment = Environment::query()->find($this->environmentId);
        $server = Server::query()->find($this->serverId);
        if ($environment === null || $server === null || $server->provisioning_status !== Server::STATUS_ACTIVE || $recipes->script($environment) === null) {
            return;
        }
        if ($recipes->queueOn($environment, $server, null) === null && $this->attempts() < $this->tries) {
            $this->release(60);
        }
    }
}
