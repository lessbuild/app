<?php

namespace App\Core\Enums;

enum ProjectResourceAccessPurpose
{
    case Interactive;

    /** Only for an independently authorized, read-only product data export. */
    case HistoricalExport;
}
