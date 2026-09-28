<?php

declare(strict_types=1);

namespace App\Contracts;

/** Where the current change came from. Both are null for console commands and queued jobs. */
interface RequestOrigin
{
    /**
     * The client IP of the request that caused the change, recorded on audit entries.
     *
     * @return string|null
     */
    public function ipAddress(): ?string;

    /**
     * The browser or client identifier of that request, recorded alongside the IP.
     *
     * @return string|null
     */
    public function userAgent(): ?string;
}
