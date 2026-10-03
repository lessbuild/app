<?php

declare(strict_types=1);

namespace App\Contracts\Infrastructure;

/** A live interactive shell on a server. */
interface TerminalConnection
{
    /**
     * Send keystrokes to the shell.
     *
     * @param  string  $input
     * @return void
     */
    public function write(string $input): void;

    /**
     * Take the output the shell produced since the last read ('' when there's none).
     *
     * @return string
     */
    public function read(): string;

    /**
     * Determine whether the shell is still running (false once the person types `exit` or the connection drops).
     *
     * @return bool
     */
    public function isRunning(): bool;

    /**
     * End the shell and the connection under it. Safe to call more than once.
     *
     * @return void
     */
    public function close(): void;
}
