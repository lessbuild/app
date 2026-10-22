<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Data\Infrastructure\ShellResult;
use App\Models\Server;
use App\Services\Infrastructure\ServerShell;

/** Answers commands with queued results (or a default), and records what ran. */
final class FakeServerShell extends ServerShell
{
    /** @var list<array{server: int, command: string, timeout: int|null}> */
    public array $ran = [];

    /** @var list<ShellResult> */
    public array $results = [];

    public function __construct() {}

    public function reply(string $output, int $exitCode = 0, string $error = ''): self
    {
        $this->results[] = new ShellResult($output, $error, $exitCode);

        return $this;
    }

    public function run(Server $server, string $command, bool $logOutput = false, ?int $timeout = null): ShellResult
    {
        $this->ran[] = ['server' => $server->id, 'command' => $command, 'timeout' => $timeout];

        return array_shift($this->results) ?? new ShellResult('ok', '', 0);
    }
}
