<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Models\Server;
use App\Services\Infrastructure\RemoteScriptRunner;

/** Records scripts that would be uploaded and started over SSH. */
final class FakeRemoteScriptRunner extends RemoteScriptRunner
{
    /** @var list<array{server: int, script: string, name: string}> */
    public array $started = [];

    public function __construct() {}

    /** @return array{id: int, path: string} */
    public function start(Server $server, string $script, string $name): array
    {
        $this->started[] = ['server' => $server->id, 'script' => $script, 'name' => $name];

        return ['id' => 4242, 'path' => "/tmp/{$name}.sh"];
    }
}
