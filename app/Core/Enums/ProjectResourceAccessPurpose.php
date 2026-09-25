<?php

namespace App\Core\Enums;

enum ProjectResourceAccessPurpose
{
    case Interactive;

    /** Only for an independently authorized, read-only product data export. */
    case HistoricalExport;

    /** Only for authorized retained-record views; never grants ordinary mutation rights. */
    case RetainedRead;

    /** Only for the explicit lifecycle workflow, after the shared project is restored. */
    case Restoration;
}
