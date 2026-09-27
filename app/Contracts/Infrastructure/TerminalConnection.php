<?php

declare(strict_types=1);

namespace App\Contracts\Infrastructure;

/** A live interactive shell on a server. */
interface TerminalConnection
{
    /** Send keystrokes to the shell. */
    public function write(string $input): void;

    /** Output the shell produced since the last read ('' when there's none). */
    public function read(): string;

    /** Whether the shell is still running (false once the person types `exit` or the connection drops). */
    public function isRunning(): bool;

    /**
     * Ends the shell and the connection under it. Safe to call more than once.
     */
    public function close(): void;
}
