<?php

namespace App\Data;

use App\Models\Build;

class BuildRedeploymentResult
{
    public const QUEUED = 'queued';

    public const INELIGIBLE = 'ineligible';

    public const UNAVAILABLE = 'unavailable';

    public const ACTIVE = 'active';

    public function __construct(
        public readonly string $status,
        public readonly ?Build $build = null,
    ) {}
}
