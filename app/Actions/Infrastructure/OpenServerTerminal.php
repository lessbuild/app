<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Actions\Audit\RecordAuditEntry;
use App\Data\Infrastructure\TerminalSize;
use App\Enums\AuditAction;
use App\Jobs\Infrastructure\RunServerTerminal;
use App\Models\Server;
use App\Models\ServerTerminalSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class OpenServerTerminal
{
    /**
     * Opens a troubleshooting terminal on a server.
     *
     * @param  RecordAuditEntry  $audit  Records it.
     */
    public function __construct(private readonly RecordAuditEntry $audit) {}

    /**
     * Open a root shell for the person, sized to their browser. Returns the session and a token the caller keeps in the
     * person's browser session: requests without it can't use the terminal.
     *
     * @return array{ServerTerminalSession, string}
     */
    public function handle(User $actor, Server $server, int $columns, int $rows): array
    {
        Gate::forUser($actor)->authorize('openTerminal', $server);

        return DB::transaction(function () use ($actor, $server, $columns, $rows): array {
            $locked = Server::query()->lockForUpdate()->findOrFail($server->id);
            if ($locked->provisioning_status !== Server::STATUS_ACTIVE || $locked->ssh_host_key === null) {
                throw ValidationException::withMessages(['terminal' => __('A terminal needs an active server with a pinned SSH host key.')]);
            }
            $open = $locked->terminalSessions()->whereIn('status', ServerTerminalSession::ACTIVE)->where('user_id', $actor->id)->get();
            $open->each(fn (ServerTerminalSession $old) => $old->forceFill(['status' => 'closed', 'close_reason' => 'replaced', 'closed_at' => now()])->save());
            $token = Str::random(64);
            $session = new ServerTerminalSession;
            $session->forceFill([
                'server_id' => $locked->id, 'user_id' => $actor->id, 'token_hash' => hash('sha256', $token), 'status' => 'connecting',
                'columns' => max(TerminalSize::MIN_COLUMNS, min(TerminalSize::MAX_COLUMNS, $columns)),
                'rows' => max(TerminalSize::MIN_ROWS, min(TerminalSize::MAX_ROWS, $rows)),
                'expires_at' => now()->addMinutes((int) config('infrastructure.terminal.session_minutes')),
                'idle_expires_at' => now()->addMinutes((int) config('infrastructure.terminal.idle_minutes')),
            ])->save();
            $this->audit->handle(AuditAction::ServerTerminalOpened, $actor, $locked->account_id, ['server' => $locked->label()]);
            RunServerTerminal::dispatch($session->id)->afterCommit();

            return [$session, $token];
        });
    }
}
