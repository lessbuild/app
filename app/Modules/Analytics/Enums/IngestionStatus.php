<?php

namespace App\Modules\Analytics\Enums;

enum IngestionStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Processed = 'processed';
    case Failed = 'failed';
}
