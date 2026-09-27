<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Models\Account;
use App\Models\Server;
use App\Models\User;
use App\Services\Infrastructure\ServerProviderResolver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class DeleteServer
{
    public function __construct(private readonly ServerProviderResolver $providers, private readonly RecordAuditEntry $audit) {}

    /**
     * Delete the cloud server and the SSH key we registered for it, then the record. Imported servers are only forgotten;
     * nothing on them is touched. If the provider refuses, nothing is deleted and the error is shown.
     */
    public function handle(Account $account, User $actor, Server $server): void
    {
        Gate::forUser($actor)->authorize('delete', $server);
        $server = Server::query()->where('account_id', $account->id)->findOrFail($server->id);
        $ownsKey = $server->ssh_fingerprint !== null && $server->ssh_key_owned;
        if ($server->provider !== null && ($server->identifier !== null || $ownsKey)) {
            $client = $this->providers->resolve($server->provider);
            try {
                if ($server->identifier !== null && ! $client->deleteServer($server->identifier)) {
                    throw new RuntimeException(__(':provider couldn’t delete the server.', ['provider' => $client->name()]));
                }
                // The key stays until the machine is gone; deleting an already-missing server counts as success, so a retry is safe.
                if ($ownsKey && ! $client->deleteSshKey((string) $server->ssh_fingerprint)) {
                    throw new RuntimeException(__(':provider couldn’t delete the server’s SSH key.', ['provider' => $client->name()]));
                }
            } catch (RuntimeException $exception) {
                report($exception);
                throw ValidationException::withMessages(['server' => $exception->getMessage()]);
            }
        }
        $server->delete();
        $this->audit->handle(AuditAction::ServerDeleted, $actor, $account->id, ['server' => $server->label(), 'ip' => $server->public_ip]);
    }
}
