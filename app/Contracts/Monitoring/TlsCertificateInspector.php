<?php

declare(strict_types=1);

namespace App\Contracts\Monitoring;

use App\Data\Monitoring\TlsCertificateInspection;

interface TlsCertificateInspector
{
    /** Connect only to the supplied public address, verifying the hostname and certificate chain. */
    public function inspect(string $hostname, string $address, int $port, int $timeoutMilliseconds): TlsCertificateInspection;
}
