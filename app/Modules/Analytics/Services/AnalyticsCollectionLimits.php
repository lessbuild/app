<?php

namespace App\Modules\Analytics\Services;

/** Shared request bounds for Analytics collection and its published API contract. */
final class AnalyticsCollectionLimits
{
    public const MAX_REQUEST_BYTES = 32768;

    public const MAX_EVENTS_PER_BATCH = 20;
}
