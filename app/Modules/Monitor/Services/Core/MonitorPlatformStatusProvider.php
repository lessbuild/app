<?php

namespace App\Modules\Monitor\Services\Core;

use App\Core\Contracts\PlatformStatusProvider;
use App\Core\Services\ProductReadinessProbe;

final class MonitorPlatformStatusProvider implements PlatformStatusProvider
{
    public function __construct(private readonly ProductReadinessProbe $readiness) {}

    public function components(): array
    {
        return $this->readiness->components('monitor', '/api/health', 'ready');
    }
}
