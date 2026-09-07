<?php

namespace App\Data;

use App\Models\Build;

class BuildRedeploymentResult
{
    public const QUEUED = 'queued';

    public const INELIGIBLE = 'ineligible';

    public const UNAVAILABLE = 'unavailable';

    public const ACTIVE = 'active';

    /**
     * Carry the outcome of a redeployment or rollback request.
     *
     * @param  'queued'|'ineligible'|'unavailable'|'active'  $status  Decision made by the corresponding action.
     * @param  Build|null  $build  Newly queued build, or null when the action did not create one.
     */
    public function __construct(
        public readonly string $status,
        public readonly ?Build $build = null,
    ) {}
}
