<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\ShipServerLogs;
use App\Actions\Infrastructure\StopServerLogs;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class UpdateServerLogShippingController
{
    /**
     * Start sending the server's logs to an environment (PUT), or stop (DELETE), and return to its logs.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Server  $server
     * @param  ShipServerLogs  $ship
     * @param  StopServerLogs  $stop
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Server $server, ShipServerLogs $ship, StopServerLogs $stop): RedirectResponse
    {
        if ($request->isMethod('DELETE')) {
            $stop->handle($user, $server);
            $message = __('Stopping. The agent is being removed.');
        } else {
            $data = $request->validate(['environment_id' => ['required', 'string']]);
            $ship->handle($user, $server, Environment::query()->forAccount($project->account)->whereKey($data['environment_id'])->firstOrFail());
            $message = __('Installing the log agent. Logs appear in Monitoring → Events within a minute.');
        }

        return to_route('infrastructure.servers.show', [$project, $server->id, 'tab' => 'logs'])->with('status', $message);
    }
}
