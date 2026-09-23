<?php

namespace App\Modules\Deployer\Data;

class BuildRevisionResult
{
    public const RECORDED = 'recorded';

    public const STALE = 'stale';

    public const MISMATCH = 'mismatch';
}
