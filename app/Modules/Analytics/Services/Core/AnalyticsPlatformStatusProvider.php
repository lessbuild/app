<?php

namespace App\Modules\Analytics\Services\Core;

use App\Core\Contracts\PlatformStatusProvider;
use App\Core\Services\ProductReadinessProbe;

final class AnalyticsPlatformStatusProvider implements PlatformStatusProvider
{
    public function __construct(private readonly ProductReadinessProbe $readiness) {}

    public function components(): array
    {
        return $this->readiness->components('analytics', '/ready', 'ok');
    }
}
