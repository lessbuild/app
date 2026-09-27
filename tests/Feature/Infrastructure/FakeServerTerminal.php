<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Contracts\Infrastructure\TerminalConnection;
use App\Data\Infrastructure\TerminalSize;
use App\Models\Server;
use App\Services\Infrastructure\ServerTerminal;
use RuntimeException;

/** A shell that answers each line with `ran: <line>` and exits on `exit`. */
final class FakeServerTerminal extends ServerTerminal
{
    /** @var list<string> */
    public array $written = [];

    public ?TerminalSize $size = null;

    public bool $refuse = false;

    public bool $closed = false;

    public function __construct() {}

    public function connect(Server $server, TerminalSize $size): TerminalConnection
    {
        if ($this->refuse) {
            throw new RuntimeException('The SSH connection couldn’t be opened.');
        }
        $this->size = $size;

        return new class($this) implements TerminalConnection
        {
            private string $output = 'root@server:~# ';

            private bool $running = true;

            public function __construct(private readonly FakeServerTerminal $terminal) {}

            public function write(string $input): void
            {
                $this->terminal->written[] = $input;
                foreach (array_filter(explode("\r", $input)) as $line) {
                    $this->output .= $line === 'exit' ? "logout\r\n" : "ran: {$line}\r\nroot@server:~# ";
                    $this->running = $this->running && $line !== 'exit';
                }
            }

            public function read(): string
            {
                [$output, $this->output] = [$this->output, ''];

                return $output;
            }

            public function isRunning(): bool
            {
                return $this->running;
            }

            public function close(): void
            {
                $this->terminal->closed = true;
            }
        };
    }
}
