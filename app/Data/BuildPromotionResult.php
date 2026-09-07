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

    /**
     * Carry the outcome of promoting a build to another environment.
     *
     * @param  'queued'|'ineligible'|'incompatible'|'unavailable'|'active'|'blocked'  $status  Decision made by the corresponding action.
     * @param  Build|null  $build  Newly queued build, or null when the action did not create one.
     */
    public function __construct(public readonly string $status, public readonly ?Build $build = null) {}
}
