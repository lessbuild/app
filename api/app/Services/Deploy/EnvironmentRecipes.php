<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Jobs\Infrastructure\ExecuteServerCommand;
use App\Models\Environment;
use App\Models\EnvironmentRecipe;
use App\Models\Server;
use App\Models\ServerCommandExecution;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Turns an environment's recipes into one command and queues it on the environment's servers. */
final class EnvironmentRecipes
{
    /**
     * Build the command that runs the environment's recipes in order as root, each announced in the output and passed
     * encoded so no quoting can break out, stopping at the first that fails. Null when there are none.
     *
     * @param  Environment  $environment
     * @return string|null
     */
    public function script(Environment $environment): ?string
    {
        $recipes = $environment->recipes()->get();
        if ($recipes->isEmpty()) {
            return null;
        }
        $lines = ['set -eo pipefail'];
        foreach ($recipes->values() as $index => $recipe) {
            /** @var EnvironmentRecipe $recipe */
            $lines[] = 'echo '.escapeshellarg(sprintf('== Recipe %d/%d: %s', $index + 1, $recipes->count(), $recipe->name));
            $lines[] = "printf '%s' ".escapeshellarg(base64_encode($recipe->script)).' | base64 --decode | bash -e';
        }

        return implode("\n", $lines);
    }

    /**
     * Get the active servers the environment's websites are on, once each.
     *
     * @param  Environment  $environment
     * @return list<Server>
     */
    public function servers(Environment $environment): array
    {
        $servers = [];
        foreach ($environment->deployedWebsites() as $website) {
            if ($website->server !== null && $website->server->provisioning_status === Server::STATUS_ACTIVE) {
                $servers[$website->server->id] = $website->server;
            }
        }

        return array_values($servers);
    }

    /**
     * Queue the environment's recipes on a server as a command in its history, unless it's busy with another command
     * (then null). The person is null when it runs by itself.
     *
     * @param  Environment  $environment
     * @param  Server  $server
     * @param  User|null  $actor
     * @return ServerCommandExecution|null
     */
    public function queueOn(Environment $environment, Server $server, ?User $actor): ?ServerCommandExecution
    {
        $script = $this->script($environment);
        if ($script === null) {
            return null;
        }

        return DB::transaction(function () use ($server, $actor, $script): ?ServerCommandExecution {
            $locked = Server::query()->lockForUpdate()->findOrFail($server->id);
            if ($locked->provisioning_status !== Server::STATUS_ACTIVE || $locked->commandExecutions()->whereIn('status', ServerCommandExecution::ACTIVE)->exists()) {
                return null;
            }
            $execution = new ServerCommandExecution;
            $execution->forceFill(['server_id' => $locked->id, 'user_id' => $actor?->id, 'command' => $script, 'status' => 'queued'])->save();
            ExecuteServerCommand::dispatch($execution->id)->afterCommit();

            return $execution;
        });
    }
}
