<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Contracts\Monitoring\TcpConnector;
use InvalidArgumentException;

final class NativeTcpConnector implements TcpConnector
{
    /**
     * Opens TCP connections for TCP monitors.
     *
     * @param  PublicWebhookTarget  $addresses  Refuses addresses that aren't public.
     */
    public function __construct(private readonly PublicWebhookTarget $addresses) {}

    /**
     * Opens and immediately closes a TCP connection to a public address, without sending anything. Refuses non-public
     * addresses.
     */
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
