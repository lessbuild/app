<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Contracts\Infrastructure\TerminalConnection;
use Closure;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/** A root shell running under `ssh -tt`, with its output buffered until the terminal's worker reads it. */
final class SshTerminalConnection implements TerminalConnection
{
    /**
     * Output the shell produced since the last read.
     *
     * @var string
     */
    private string $buffer = '';

    /**
     * Create a new SshTerminalConnection instance.
     *
     * Wraps an SSH process that hasn't been started yet.
     *
     * @param  Process  $process  The `ssh -tt` process.
     * @param  InputStream  $input  The shell's standard input, fed with keystrokes.
     * @param  Closure(): void  $release  Removes the connection's temporary key files once it closes.
     */
    public function __construct(private readonly Process $process, private readonly InputStream $input, private readonly Closure $release) {}

    /**
     * Start the shell, collecting everything it prints into the buffer.
     *
     * @return void
     */
    public function start(): void
    {
        $this->process->start(function (string $type, string $data): void {
            $this->buffer .= $data;
        });
    }

    /**
     * Send keystrokes to the shell.
     *
     * @param  string  $input
     * @return void
     */
    public function write(string $input): void
    {
        $this->input->write($input);
    }

    /**
     * Take the output collected since the last read. Polling the process lets it deliver pending output, and the
     * process's own copy is cleared so a long session doesn't grow without bound.
     *
     * @return string
     */
    public function read(): string
    {
        $this->process->isRunning();
        $this->process->clearOutput();
        $this->process->clearErrorOutput();
        [$output, $this->buffer] = [$this->buffer, ''];

        return $output;
    }

    /**
     * Determine whether the shell is still running.
     *
     * @return bool
     */
    public function isRunning(): bool
    {
        return $this->process->isRunning();
    }

    /**
     * Close the shell's input, stops it if it's still running (allowing three seconds), and always releases the
     * connection's temporary files.
     *
     * @return void
     */
    public function close(): void
    {
        $this->input->close();
        try {
            if ($this->process->isRunning()) {
                $this->process->stop(3);
            }
        } finally {
            ($this->release)();
        }
    }
}
