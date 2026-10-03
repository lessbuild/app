<?php

declare(strict_types=1);

namespace App\Jobs\Admin;

use App\Contracts\Telemetry\TelemetryIngestor;
use App\Services\Admin\SelfMonitoring;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Puts one of the platform's own exceptions into its operations project, off the request that hit it. */
final class ReportPlatformException implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Try once: a report that can't be delivered isn't worth retrying.
     *
     * @var int
     */
    public int $tries = 1;

    /**
     * Create a new ReportPlatformException instance.
     *
     * @param  array<string, mixed>  $event  The exception event, in the ingest API's shape.
     */
    public function __construct(public readonly array $event)
    {
        $this->onConnection('telemetry')->onQueue('telemetry');
    }

    /**
     * Ingest the event.
     *
     * @param  SelfMonitoring  $monitoring
     * @param  TelemetryIngestor  $ingestor
     * @return void
     */
    public function handle(SelfMonitoring $monitoring, TelemetryIngestor $ingestor): void
    {
        $monitoring->ingest($this->event, $ingestor);
    }
}
