<?php

declare(strict_types=1);

namespace App\Enums;

enum IngestionStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Processed = 'processed';
    case Failed = 'failed';
}
