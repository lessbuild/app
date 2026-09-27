<?php

declare(strict_types=1);

namespace App\Enums;

enum AlertDeliveryStatus: string
{
    case Queued = 'queued';
    case Sending = 'sending';
    case Retrying = 'retrying';
    case Accepted = 'accepted';
    case Failed = 'failed';
    case Uncertain = 'uncertain';
    case Cancelled = 'cancelled';

    /**
     * Whether the delivery is still in flight (queued, sending or waiting to retry), so it can't be retried by hand yet.
     */
    public function pending(): bool
    {
        return in_array($this, [self::Queued, self::Sending, self::Retrying], true);
    }

    /**
     * Whether someone may resend it by hand: it failed, or the provider's answer was ambiguous and it may not have
     * arrived.
     */
    public function retryable(): bool
    {
        return in_array($this, [self::Failed, self::Uncertain], true);
    }

    /**
     * The status as the delivery history shows it.
     */
    public function label(): string
    {
        return match ($this) {
            self::Accepted => __('Accepted by provider'),
            self::Uncertain => __('Uncertain — review first'),
            default => __(ucfirst($this->value)),
        };
    }
}
