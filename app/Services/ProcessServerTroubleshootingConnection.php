<?php

namespace App\Services;

use App\Contracts\ServerTroubleshootingConnection;
use App\Data\ServerTroubleshootingTerminalSize;
use App\Exceptions\ServerTroubleshootingTransportException;
use Closure;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Throwable;

class ProcessServerTroubleshootingConnection implements ServerTroubleshootingConnection
{
    private string $pendingOutput = '';

    private bool $outputOverflowed = false;

    private bool $closed = false;

    private bool $started = false;

    public function __construct(
        private readonly Process $process,
        private readonly InputStream $input,
        private readonly int $maximumInputBytes,
        private readonly int $maximumOutputBytes,
        private readonly ?Closure $release = null,
    ) {}

    /** Start the local process exactly once; the transport returns only started connections. */
    public function start(): void
    {
        if ($this->started) {
            throw new ServerTroubleshootingTransportException('The troubleshooting connection has already started.');
        }

        try {
            $this->process->setTimeout(null);
            $this->process->setOptions(['create_process_group' => true]);
            $this->started = true;
            $this->process->start($this->capture(...));
        } catch (Throwable $exception) {
            $this->close();

            throw new ServerTroubleshootingTransportException(
                'Unable to start the troubleshooting connection.',
                previous: $exception,
            );
        }
    }

    /** Reclaim an unclosed local process if the broker loses its connection object. */
    public function __destruct()
    {
        $this->close();
    }

    /**
     * Queue one bounded frame for the long-lived process. Terminal control bytes
     * are intentionally allowed; the byte limit prevents an unbounded input queue.
     */
    public function write(string $input): void
    {
        $this->ensureUsable();

        if (strlen($input) > $this->maximumInputBytes) {
            throw new ServerTroubleshootingTransportException('The troubleshooting input frame is too large.');
        }

        try {
            $this->input->write($input);
        } catch (Throwable $exception) {
            throw new ServerTroubleshootingTransportException(
                'Unable to write to the troubleshooting connection.',
                previous: $exception,
            );
        }
    }

    /**
     * Poll output without retaining a transcript. The process callback drains
     * Symfony's temporary output buffers as each chunk arrives.
     */
    public function read(): string
    {
        $this->ensureUsable();

        $this->process->isRunning();

        if ($this->outputOverflowed) {
            $this->close();

            throw new ServerTroubleshootingTransportException('The troubleshooting output frame is too large.');
        }

        $output = $this->pendingOutput;
        $this->pendingOutput = '';

        return $output;
    }

    /**
     * SSH exposes a PTY but Symfony Process has no portable window-size API.
     * Validated dimensions are therefore sent as a shell control frame; the
     * future broker must serialize it with other input and surface failures.
     */
    public function resize(ServerTroubleshootingTerminalSize $size): void
    {
        $this->write(sprintf("stty rows %d cols %d\n", $size->rows, $size->columns));
    }

    public function isRunning(): bool
    {
        if (! $this->started || $this->closed) {
            return false;
        }

        return $this->process->isRunning();
    }

    /** Stop the local process and release temporary SSH credential files. */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->input->close();

        try {
            if ($this->started && $this->process->isRunning()) {
                $this->process->stop((float) config('lessbuild.troubleshooting.process_stop_seconds', 3));
            }
        } catch (Throwable) {
            // Cleanup is best effort here; the worker will mark the session
            // terminal and a later supervisor restart must reclaim its group.
        } finally {
            if ($this->release !== null) {
                ($this->release)();
            }
        }
    }

    /** @param 'out'|'err' $type */
    private function capture(string $type, string $data): void
    {
        if ($data === '') {
            return;
        }

        $remaining = max(0, $this->maximumOutputBytes - strlen($this->pendingOutput));
        if ($remaining > 0) {
            $this->pendingOutput .= substr($data, 0, $remaining);
        }

        if (strlen($data) > $remaining) {
            $this->outputOverflowed = true;
        }

        if ($type === Process::OUT) {
            $this->process->clearOutput();
        } else {
            $this->process->clearErrorOutput();
        }
    }

    private function ensureUsable(): void
    {
        if (! $this->started || $this->closed) {
            throw new ServerTroubleshootingTransportException('The troubleshooting connection is not available.');
        }
    }
}
