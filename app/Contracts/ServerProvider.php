<?php

namespace App\Contracts;

use App\Data\CloudServerData;
use App\Data\CloudSshKeyData;

interface ServerProvider
{
    public function name(): string;

    public function createSshKey(string $name, string $publicKey): CloudSshKeyData;

    public function deleteSshKey(string $fingerprint): bool;

    public function createServer(array $parameters): CloudServerData;

    public function server(int $identifier): CloudServerData;

    public function deleteServer(int $identifier): bool;

    public function regions(): array;

    public function sizes(): array;
}
