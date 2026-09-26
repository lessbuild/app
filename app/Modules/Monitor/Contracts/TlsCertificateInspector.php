<?php

namespace App\Modules\Monitor\Contracts;

use App\Modules\Monitor\Data\Telemetry\TlsCertificateInspection;

interface TlsCertificateInspector
{
    /** Connect only to the supplied public address, verifying the hostname and certificate chain. */
    public function inspect(string $hostname, string $address, int $port, int $timeoutMilliseconds): TlsCertificateInspection;
}
