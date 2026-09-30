<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Jobs\Infrastructure\SyncServerTask;
use App\Models\Server;
use App\Models\ServerService;
use App\Models\User;
use App\Support\Infrastructure\ServerTaskKinds;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class InstallServerService
{
    /**
     * Create a new InstallServerService instance.
     *
     * @param  RecordAuditEntry  $audit  Records the change.
     */
    public function __construct(private readonly RecordAuditEntry $audit) {}

    /**
     * Install Meilisearch, Typesense or Redis on a server with a generated key or password (queued), or change where an
     * installed one listens: only on the server, or also on its private IP for the account's other servers.
     *
     * @param  User  $actor
     * @param  Server  $server
     * @param  string  $kind
     * @param  string  $listen  local or private
     * @return ServerService
     *
     * @throws ValidationException
     */
    public function handle(User $actor, Server $server, string $kind, string $listen): ServerService
    {
        Gate::forUser($actor)->authorize('runCommands', $server);
        if (! isset(ServerService::KINDS[$kind])) {
            throw ValidationException::withMessages(['kind' => __('Choose Meilisearch, Typesense or Redis.')]);
        }
        if (! in_array($listen, ['local', 'private'], true) || ($listen === 'private' && $server->private_ip === null)) {
            throw ValidationException::withMessages(['listen' => __('This server has no private IP, so the service can only listen on the server itself.')]);
        }
        $service = ServerService::query()->where('server_id', $server->id)->where('kind', $kind)->first() ?? new ServerService;
        $service->forceFill([
            'server_id' => $server->id,
            'kind' => $kind,
            'port' => ServerService::KINDS[$kind]['port'],
            'secret' => $service->exists ? $service->secret : Str::random(40),
            'listen' => $listen,
            'status' => 'pending',
            'error' => null,
            'created_by' => $service->created_by ?? $actor->id,
        ])->save();
        $this->audit->handle(AuditAction::ServerTaskSaved, $actor, $server->account_id, ['task' => ServerTaskKinds::describe($service), 'server' => $server->label()]);
        SyncServerTask::dispatch(ServerService::class, $service->id, 'apply')->afterCommit();

        return $service;
    }
}
