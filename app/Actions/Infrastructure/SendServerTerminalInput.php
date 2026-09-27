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
    public function __construct(private readonly TerminalFrames $frames) {}

    /** Queue keystrokes for the shell and keep the session from going idle. */
    public function handle(User $actor, ServerTerminalSession $terminal, string $token, string $input): int
    {
        Gate::forUser($actor)->authorize('use', $terminal);
        StateConflict::unless(hash_equals($terminal->token_hash, hash('sha256', $token)), __('This terminal was opened in another browser.'));
        StateConflict::unless($terminal->isActive() && ! $terminal->hasExpired(), __('This terminal has closed.'));
        $terminal->forceFill(['idle_expires_at' => $this->idleUntil($terminal)])->save();

        return $this->frames->pushInput($terminal, $input);
    }

    private function idleUntil(ServerTerminalSession $terminal): \Carbon\CarbonImmutable
    {
        $idle = now()->toImmutable()->addMinutes((int) config('infrastructure.terminal.idle_minutes'));

        return $idle->min($terminal->expires_at);
    }
}
