<?php

declare(strict_types=1);

namespace App\Contracts\Monitoring;

interface TcpConnector
{
    /** Connect only to the supplied public IP address, without sending application data. */
    public function connect(string $address, int $port, int $timeoutMilliseconds): bool;
}
