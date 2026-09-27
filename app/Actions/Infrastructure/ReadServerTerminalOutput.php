<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Exceptions\StateConflict;
use App\Models\ServerTerminalSession;
use App\Models\User;
use App\Services\Infrastructure\TerminalFrames;
use Illuminate\Support\Facades\Gate;

final class ReadServerTerminalOutput
{
    public function __construct(private readonly TerminalFrames $frames) {}

    /**
     * Output after the browser's last sequence, and the session's state (so the page knows when it has closed).
     *
     * @return array{status: string, reason: string|null, frames: list<array{sequence: int, data: string}>}
     */
    public function handle(User $actor, ServerTerminalSession $terminal, string $token, int $after): array
    {
        Gate::forUser($actor)->authorize('use', $terminal);
        StateConflict::unless(hash_equals($terminal->token_hash, hash('sha256', $token)), __('This terminal was opened in another browser.'));

        return ['status' => $terminal->status, 'reason' => $terminal->close_reason, 'frames' => $this->frames->output($terminal, max(0, $after))];
    }
}
