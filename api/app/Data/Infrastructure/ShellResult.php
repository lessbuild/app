<?php

declare(strict_types=1);

namespace App\Data\Infrastructure;

/** What a command run over SSH printed and how it exited. */
final readonly class ShellResult
{
    /**
     * Create a new ShellResult instance.
     *
     * The result of running a command on a server.
     *
     * @param  string  $output  What it wrote to standard output.
     * @param  string  $errorOutput  What it wrote to standard error.
     * @param  ?int  $exitCode  Its exit code; null when the connection dropped before it finished.
     */
    public function __construct(public string $output, public string $errorOutput, public ?int $exitCode) {}

    /**
     * Determine whether the command finished with exit code 0.
     *
     * @return bool
     */
    public function successful(): bool
    {
        return $this->exitCode === 0;
    }

    /**
     * Get standard output and error together, trimmed.
     *
     * @return string
     */
    public function combined(): string
    {
        return trim(implode(PHP_EOL, array_filter([$this->output, $this->errorOutput], fn (string $part): bool => $part !== '')));
    }
}
