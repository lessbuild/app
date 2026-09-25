<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Contracts\PlatformStatusProvider;
use App\Modules\Deployer\Services\PublicPlatformStatus;

final class DeployerPlatformStatusProvider implements PlatformStatusProvider
{
    public function __construct(private readonly PublicPlatformStatus $status) {}

    public function components(): array
    {
        return array_map(static fn (array $component): array => [
            'name' => (string) $component['name'],
            'description' => (string) $component['description'],
            'operational' => (bool) $component['operational'],
        ], $this->status->snapshot()['components']);
    }
}
