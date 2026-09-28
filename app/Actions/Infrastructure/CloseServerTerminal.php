<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Models\ServerTerminalSession;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class CloseServerTerminal
{
    /**
     * Closes a troubleshooting terminal.
     *
     * @param  RecordAuditEntry  $audit  Records it.
     */
    public function __construct(private readonly RecordAuditEntry $audit) {}

    /**
     * Close the terminal; the broker sees it and hangs up the shell.
     *
     * @param  User  $actor
     * @param  ServerTerminalSession  $terminal
     * @return void
     */
    public function handle(User $actor, ServerTerminalSession $terminal): void
    {
        Gate::forUser($actor)->authorize('use', $terminal);
        if (ServerTerminalSession::query()->whereKey($terminal->id)->whereIn('status', ServerTerminalSession::ACTIVE)
            ->update(['status' => 'closed', 'close_reason' => 'closed by you', 'closed_at' => now()->format('Y-m-d H:i:s.u')]) === 1) {
            $this->audit->handle(AuditAction::ServerTerminalClosed, $actor, $terminal->server->account_id, ['server' => $terminal->server->label()]);
        }
    }
}
