<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Contracts\TcpConnector;
use InvalidArgumentException;

final class NativeTcpConnector implements TcpConnector
{
    public function __construct(private readonly PublicWebhookTarget $addresses) {}

    public function connect(string $address, int $port, int $timeoutMilliseconds): bool
    {
        if ($port < 1 || $port > 65535 || $timeoutMilliseconds < 1 || ! $this->addresses->isPublic($address)) {
            throw new InvalidArgumentException('A pinned public IP, valid port and positive timeout are required.');
        }

        $authority = str_contains($address, ':') ? '['.$address.']' : $address;
        $errorNumber = 0;
        $errorMessage = '';
        $socket = @stream_socket_client(
            'tcp://'.$authority.':'.$port,
            $errorNumber,
            $errorMessage,
            max(0.001, min(20.0, $timeoutMilliseconds / 1000)),
            STREAM_CLIENT_CONNECT,
        );

        if (! is_resource($socket)) {
            return false;
        }

        fclose($socket);

        return true;
    }
}
