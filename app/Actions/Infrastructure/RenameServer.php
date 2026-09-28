<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Models\Account;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class RenameServer
{
    /**
     * Changes a server's display name.
     *
     * @param  RecordAuditEntry  $audit  Records it.
     */
    public function __construct(private readonly RecordAuditEntry $audit) {}

    /**
     * Set the name shown in the app. The machine's hostname stays as created.
     *
     * @param  Account  $account
     * @param  User  $actor
     * @param  Server  $server
     * @param  string|null  $displayName
     * @return void
     */
    public function handle(Account $account, User $actor, Server $server, ?string $displayName): void
    {
        Gate::forUser($actor)->authorize('update', $server);
        $server = Server::query()->where('account_id', $account->id)->findOrFail($server->id);
        $previous = $server->label();
        $server->forceFill(['display_name' => $displayName !== null && trim($displayName) !== '' ? trim($displayName) : null])->save();
        $this->audit->handle(AuditAction::ServerRenamed, $actor, $account->id, ['server' => $previous, 'name' => $server->label()]);
    }
}
