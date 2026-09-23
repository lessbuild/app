<?php

namespace App\Modules\Deployer\Data;

class WebsiteHealthProbeResult
{
    /**
     * Carry the bounded result of one remote website health probe.
     *
     * @param  bool  $successful  Whether the remote command completed with a successful HTTP response.
     * @param  string|null  $error  Sanitized, bounded failure detail when the probe did not succeed.
     * @param  int|null  $httpStatus  Parsed HTTP status code, when the remote probe reported one.
     * @param  int|null  $durationMs  Parsed response duration in milliseconds, when reported.
     */
    public function __construct(
        public readonly bool $successful,
        public readonly ?string $error,
        public readonly ?int $httpStatus,
        public readonly ?int $durationMs,
    ) {}
}
