<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Models\ServerTerminalFrame;
use App\Models\ServerTerminalSession;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** The frame queue between the browser and the broker: sequenced, encrypted, and deleted once taken. */
class TerminalFrames
{
    /**
     * Queues keystrokes for the terminal's worker with the next input sequence number, which is returned.
     */
    public function pushInput(ServerTerminalSession $session, string $input): int
    {
        return DB::transaction(function () use ($session, $input): int {
            $locked = ServerTerminalSession::query()->lockForUpdate()->findOrFail($session->id);
            $sequence = $locked->input_sequence + 1;
            $this->frame($locked, 'in', $sequence, $input);
            $locked->forceFill(['input_sequence' => $sequence])->save();

            return $sequence;
        });
    }

    /** Take (and delete) the waiting input, oldest first. */
    public function takeInput(ServerTerminalSession $session): string
    {
        $frames = ServerTerminalFrame::query()->where('server_terminal_session_id', $session->id)->where('direction', 'in')->orderBy('sequence')->get();
        ServerTerminalFrame::query()->whereKey($frames->modelKeys())->delete();

        return $frames->pluck('payload')->implode('');
    }

    /**
     * Queues output for the browser in frames of the configured size. When too much output is waiting because the
     * browser stopped collecting it, the terminal is ended instead.
     *
     * @throws RuntimeException when the browser has stopped collecting output
     */
    public function pushOutput(ServerTerminalSession $session, string $output): void
    {
        if ($output === '') {
            return;
        }
        $pending = (int) ServerTerminalFrame::query()->where('server_terminal_session_id', $session->id)->where('direction', 'out')->sum('bytes');
        if ($pending + strlen($output) > (int) config('infrastructure.terminal.max_pending_output_bytes')) {
            throw new RuntimeException('The browser stopped collecting output.');
        }
        DB::transaction(function () use ($session, $output): void {
            $locked = ServerTerminalSession::query()->lockForUpdate()->findOrFail($session->id);
            $sequence = $locked->output_sequence;
            foreach (str_split($output, max(1, (int) config('infrastructure.terminal.output_frame_bytes'))) as $chunk) {
                $this->frame($locked, 'out', ++$sequence, $chunk);
            }
            $locked->forceFill(['output_sequence' => $sequence])->save();
        });
    }

    /**
     * Output after `$after`; frames up to `$after` have reached the browser and are deleted.
     *
     * @return list<array{sequence: int, data: string}>
     */
    public function output(ServerTerminalSession $session, int $after, int $limit = 100): array
    {
        ServerTerminalFrame::query()->where('server_terminal_session_id', $session->id)->where('direction', 'out')->where('sequence', '<=', $after)->delete();

        return array_values(ServerTerminalFrame::query()->where('server_terminal_session_id', $session->id)->where('direction', 'out')->where('sequence', '>', $after)
            ->orderBy('sequence')->limit($limit)->get()
            ->map(fn (ServerTerminalFrame $frame): array => ['sequence' => $frame->sequence, 'data' => $frame->payload])->all());
    }

    /**
     * Stores one frame.
     */
    private function frame(ServerTerminalSession $session, string $direction, int $sequence, string $payload): void
    {
        $frame = new ServerTerminalFrame;
        $frame->forceFill(['server_terminal_session_id' => $session->id, 'direction' => $direction, 'sequence' => $sequence, 'payload' => $payload, 'bytes' => strlen($payload)])->save();
    }
}
