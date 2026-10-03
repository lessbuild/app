<?php

declare(strict_types=1);

namespace App\Enums;

/** Where an Audit run is. */
enum SiteAuditStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';

    /**
     * Determine whether the run has stopped, either way.
     *
     * @return bool
     */
    public function finished(): bool
    {
        return $this === self::Done || $this === self::Failed;
    }
}
