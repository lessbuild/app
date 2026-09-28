<?php

declare(strict_types=1);

namespace App\Enums;

enum IngestStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Retrying = 'retrying';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * The receipt status as shown on the ingest receipts page.
     *
     * @return string
     */
    public function label(): string
    {
        return __(ucfirst($this->value));
    }

    /**
     * The badge colour for the status.
     *
     * @return string
     */
    public function tone(): string
    {
        return match ($this) {
            self::Completed => 'success',
            self::Failed => 'danger',
            self::Retrying => 'warning',
            default => 'neutral',
        };
    }
}
