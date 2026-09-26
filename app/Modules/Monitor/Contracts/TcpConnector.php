<?php

namespace App\Modules\Monitor\Contracts;

interface TcpConnector
{
    /** Connect only to the supplied public IP address, without sending application data. */
    public function connect(string $address, int $port, int $timeoutMilliseconds): bool;
}
