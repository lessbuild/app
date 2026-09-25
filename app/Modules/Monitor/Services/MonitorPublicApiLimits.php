<?php

namespace App\Modules\Monitor\Services;

/** Shared limits for Monitor's public ingestion routes and generated OpenAPI contract. */
final class MonitorPublicApiLimits
{
    public const INGEST_TOKEN_PER_MINUTE = 240;

    public const DEPLOYMENT_TOKEN_PER_MINUTE = 60;

    public const HEARTBEAT_IP_PER_MINUTE = 240;

    public const HEARTBEAT_MONITOR_PER_MINUTE = 60;

    public const QUEUE_IP_PER_MINUTE = 2400;

    public const QUEUE_SNAPSHOT_MONITOR_PER_MINUTE = 60;

    public const QUEUE_WORKER_MONITOR_PER_MINUTE = 600;
}
