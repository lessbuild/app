<?php

declare(strict_types=1);

namespace App\Domain\Audit\Contracts;

/** Where the current change came from. Both are null for console commands and queued jobs. */
interface RequestOrigin
{
    public function ipAddress(): ?string;

    public function userAgent(): ?string;
}
