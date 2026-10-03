<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Exceptions\StateConflict;
use App\Models\ServerTerminalSession;
use App\Models\User;
use App\Services\Infrastructure\TerminalFrames;
use Illuminate\Support\Facades\Gate;

final class SendServerTerminalInput
{
    /**
     * Create a new SendServerTerminalInput instance.
     *
     * Passes keystrokes from the browser to a terminal.
     *
     * @param  TerminalFrames  $frames  Queues the input for the terminal's worker.
     */
    public function __construct(private readonly TerminalFrames $frames) {}

    /**
     * Queue keystrokes for the shell and keep the session from going idle.
     *
     * @param  User  $actor
     * @param  ServerTerminalSession  $terminal
     * @param  string  $token
     * @param  string  $input
     * @return int
     */
    public function handle(User $actor, ServerTerminalSession $terminal, string $token, string $input): int
    {
        Gate::forUser($actor)->authorize('use', $terminal);
        StateConflict::unless(hash_equals($terminal->token_hash, hash('sha256', $token)), __('This terminal was opened in another browser.'));
        StateConflict::unless($terminal->isActive() && ! $terminal->hasExpired(), __('This terminal has closed.'));
        $terminal->forceFill(['idle_expires_at' => $this->idleUntil($terminal)])->save();

        return $this->frames->pushInput($terminal, $input);
    }

    /**
     * Work out when the terminal closes if nothing more is typed: the idle timeout from now, but never past its time
     * limit.
     *
     * @param  ServerTerminalSession  $terminal
     * @return \Carbon\CarbonImmutable
     */
    private function idleUntil(ServerTerminalSession $terminal): \Carbon\CarbonImmutable
    {
        $idle = now()->toImmutable()->addMinutes((int) config('infrastructure.terminal.idle_minutes'));

        return $idle->min($terminal->expires_at);
    }
}
