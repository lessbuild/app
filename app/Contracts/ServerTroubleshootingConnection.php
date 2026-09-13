<?php

namespace App\Contracts;

use App\Data\ServerTroubleshootingTerminalSize;

interface ServerTroubleshootingConnection
{
    /** Send one bounded input frame to the transport-owned terminal. */
    public function write(string $input): void;

    /** Return output captured since the previous poll. */
    public function read(): string;

    /** Send a validated terminal-size control frame. */
    public function resize(ServerTroubleshootingTerminalSize $size): void;

    /** Report whether the transport-owned process is still connected. */
    public function isRunning(): bool;

    /** Idempotently close the local and transport-owned process. */
    public function close(): void;
}
