<?php

namespace App\Modules\Monitor\Data\Telemetry;

final readonly class AlertDeliveryResult
{
    public function __construct(
        public AlertDeliveryStatus $status,
        public ?string $errorCode = null,
        public ?int $httpStatus = null,
        public ?int $retryAfter = null,
    ) {}
}
