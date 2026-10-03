<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Actions\Telemetry\CreateIngestToken;
use App\Actions\Telemetry\RevokeIngestToken;
use App\Exceptions\AccountRuleViolation;
use App\Jobs\Infrastructure\InstallLogAgent;
use App\Models\Environment;
use App\Models\Server;
use App\Models\ServerLogShipping;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class ShipServerLogs
{
    /**
     * Create a new ShipServerLogs instance.
     *
     * @param  CreateIngestToken  $tokens  Creates the agent's key.
     * @param  RevokeIngestToken  $revoke  Revokes a previous key.
     */
    public function __construct(private readonly CreateIngestToken $tokens, private readonly RevokeIngestToken $revoke) {}

    /**
     * Send a server's logs to a Monitoring environment of the same account: give the agent a new key of its own (the
     * old one, if any, is revoked) and queue its install.
     *
     * @param  User  $actor
     * @param  Server  $server
     * @param  Environment  $environment
     * @return ServerLogShipping
     */
    public function handle(User $actor, Server $server, Environment $environment): ServerLogShipping
    {
        Gate::forUser($actor)->authorize('update', $server);
        Gate::forUser($actor)->authorize('manageService', [$environment->project, 'monitoring']);
        if ($environment->project->account_id !== $server->account_id) {
            throw new AccountRuleViolation('environment_id', __('Choose an environment in this account.'));
        }
        if ($server->provisioning_status !== Server::STATUS_ACTIVE) {
            throw new AccountRuleViolation('environment_id', __('The server needs to finish setting up first.'));
        }

        return DB::transaction(function () use ($actor, $server, $environment): ServerLogShipping {
            $shipping = ServerLogShipping::query()->with('ingestToken')->where('server_id', $server->id)->first() ?? new ServerLogShipping;
            if ($shipping->ingestToken !== null && $shipping->ingestToken->revoked_at === null) {
                $this->revoke->handle($actor, $shipping->ingestToken);
            }
            $issued = $this->tokens->handle($actor, $environment, 'Server logs: '.$server->name);
            $shipping->forceFill(['server_id' => $server->id, 'environment_id' => $environment->id, 'ingest_token_id' => $issued->token->id, 'status' => 'installing', 'last_error' => null])->save();
            InstallLogAgent::dispatch($shipping->id, $issued->secret)->afterCommit();

            return $shipping;
        });
    }
}
