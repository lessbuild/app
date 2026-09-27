<?php

declare(strict_types=1);

namespace App\Data\Infrastructure;

/** What a command run over SSH printed and how it exited. */
final readonly class ShellResult
{
    public function __construct(public string $output, public string $errorOutput, public ?int $exitCode) {}

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }

    /** Standard output and error together, trimmed. */
    public function combined(): string
    {
        return trim(implode(PHP_EOL, array_filter([$this->output, $this->errorOutput], fn (string $part): bool => $part !== '')));
    }
}
