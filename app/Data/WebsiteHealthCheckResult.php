<?php

namespace App\Data;

class WebsiteHealthCheckResult
{
    public const QUEUED = 'queued';

    public const DISABLED = 'disabled';

    public const INACTIVE = 'inactive';

    /**
     * Carry the bounded outcome of a manual website health-check request.
     *
     * @param  'queued'|'disabled'|'inactive'  $status  Result of the eligibility checks and dispatch.
     */
    public function __construct(public readonly string $status) {}
}
