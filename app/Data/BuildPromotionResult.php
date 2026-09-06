<?php

namespace App\Data;

use App\Models\Build;

class BuildPromotionResult
{
    public const QUEUED = 'queued';

    public const INELIGIBLE = 'ineligible';

    public const INCOMPATIBLE = 'incompatible';

    public const UNAVAILABLE = 'unavailable';

    public const ACTIVE = 'active';

    public const BLOCKED = 'blocked';

    public function __construct(public readonly string $status, public readonly ?Build $build = null) {}
}
