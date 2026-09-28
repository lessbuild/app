<?php

declare(strict_types=1);

namespace App\Enums;

enum IngestSource: string
{
    case Json = 'json';
    case OtlpTraces = 'otlp_traces';
    case OtlpLogs = 'otlp_logs';
    case OtlpMetrics = 'otlp_metrics';

    /**
     * How the ingest receipts page names the source.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::Json => 'JSON events',
            self::OtlpTraces => 'OTLP traces',
            self::OtlpLogs => 'OTLP logs',
            self::OtlpMetrics => 'OTLP metrics',
        };
    }

    /**
     * The OTLP signal the source carries, or null for our own JSON event format.
     *
     * @return string|null
     */
    public function signal(): ?string
    {
        return match ($this) {
            self::Json => null,
            self::OtlpTraces => 'traces',
            self::OtlpLogs => 'logs',
            self::OtlpMetrics => 'metrics',
        };
    }
}
