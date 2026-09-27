<?php

declare(strict_types=1);

namespace App\Jobs\Infrastructure;

use App\Contracts\Infrastructure\TerminalConnection;
use App\Data\Infrastructure\ServerTroubleshootingTerminalSize;
use App\Models\ServerTerminalSession;
use App\Services\Infrastructure\ServerTerminal;
use App\Services\Infrastructure\TerminalFrames;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;
use Throwable;

/**
 * The broker for one terminal: holds the SSH shell for the session's lifetime on the `terminals` queue, passing input
 * frames to it and its output back as frames, until the shell exits or the session closes or expires.
 */
final class RunServerTerminal implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /** The session's time limit plus room to hang up. */
    public int $timeout;

    public function __construct(public readonly string $sessionId)
    {
        $this->timeout = (int) config('infrastructure.terminal.session_minutes') * 60 + 120;
        $this->onConnection((string) config('infrastructure.terminal.connection'))->onQueue((string) config('infrastructure.terminal.queue'));
    }

    public function handle(ServerTerminal $terminals, TerminalFrames $frames): void
    {
        $session = ServerTerminalSession::query()->with('server')->whereKey($this->sessionId)->where('status', 'connecting')->first();
        if ($session === null) {
            return;
        }
        $connection = null;
        try {
            $connection = $terminals->connect($session->server, new ServerTroubleshootingTerminalSize($session->columns, $session->rows));
            $session->forceFill(['status' => 'connected', 'connected_at' => now(), 'broker_seen_at' => now()])->save();
            $reason = $this->relay($session, $connection, $frames);
        } catch (Throwable $exception) {
            $this->finish($session, 'failed', $exception instanceof RuntimeException ? $exception->getMessage() : 'The connection failed.');

            return;
        } finally {
            $connection?->close();
        }
        $this->finish($session, $reason === 'expired' ? 'expired' : 'closed', $reason);
    }

    public function failed(Throwable $exception): void
    {
        $session = ServerTerminalSession::query()->find($this->sessionId);
        if ($session !== null) {
            $this->finish($session, 'failed', 'The terminal worker stopped.');
        }
    }

    /** @return string why it ended */
    private function relay(ServerTerminalSession $session, TerminalConnection $connection, TerminalFrames $frames): string
    {
        $pause = max(0, (int) config('infrastructure.terminal.poll_milliseconds')) * 1000;
        $lastBeat = CarbonImmutable::now();
        while (true) {
            $session->refresh();
            if (! $session->isActive()) {
                return $session->close_reason ?? 'closed';
            }
            if ($session->hasExpired()) {
                return 'expired';
            }
            $input = $frames->takeInput($session);
            if ($input !== '') {
                $connection->write($input);
            }
            $frames->pushOutput($session, $connection->read());
            if (! $connection->isRunning()) {
                $frames->pushOutput($session, $connection->read());

                return 'shell exited';
            }
            if ($lastBeat->diffInSeconds(CarbonImmutable::now()) >= 5) {
                $session->forceFill(['broker_seen_at' => now()])->save();
                $lastBeat = CarbonImmutable::now();
            }
            usleep($pause);
        }
    }

    private function finish(ServerTerminalSession $session, string $status, string $reason): void
    {
        ServerTerminalSession::query()->whereKey($session->id)->whereIn('status', ServerTerminalSession::ACTIVE)
            ->update(['status' => $status, 'close_reason' => str($reason)->limit(40, '')->toString(), 'closed_at' => now()->format('Y-m-d H:i:s.u')]);
    }
}
