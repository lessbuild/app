<?php

declare(strict_types=1);

namespace App\Data\Monitoring;

use App\Enums\AlertDeliveryStatus;

final readonly class AlertDeliveryResult
{
    /**
     * What happened when an alert was handed to a destination.
     *
     * @param  AlertDeliveryStatus  $status  Accepted, failed, or uncertain (it may have arrived).
     * @param  ?string  $errorCode  A stable code for why it failed, shown on the delivery history.
     * @param  ?int  $httpStatus  The destination's response status, for webhook-style destinations.
     * @param  ?int  $retryAfter  Seconds the destination asked us to wait before retrying; otherwise the standard
     *                            backoff applies.
     */
    public function __construct(
        public AlertDeliveryStatus $status,
        public ?string $errorCode = null,
        public ?int $httpStatus = null,
        public ?int $retryAfter = null,
    ) {}
}
