<?php

declare(strict_types=1);

namespace App\Data\Monitoring;

use App\Enums\AlertDeliveryStatus;

final readonly class AlertDeliveryResult
{
    public function __construct(
        public AlertDeliveryStatus $status,
        public ?string $errorCode = null,
        public ?int $httpStatus = null,
        public ?int $retryAfter = null,
    ) {}
}
