<?php

namespace App\Modules\Monitor\Data\Telemetry;

enum AlertDeliveryStatus: string
{
    case Queued = 'queued';
    case Sending = 'sending';
    case Retrying = 'retrying';
    case Accepted = 'accepted';
    case Failed = 'failed';
    case Uncertain = 'uncertain';
    case Cancelled = 'cancelled';

    public function pending(): bool
    {
        return in_array($this, [self::Queued, self::Sending, self::Retrying], true);
    }

    public function retryable(): bool
    {
        return in_array($this, [self::Failed, self::Uncertain], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Accepted => 'Accepted by provider',
            self::Uncertain => 'Uncertain — review first',
            default => ucfirst($this->value),
        };
    }
}
