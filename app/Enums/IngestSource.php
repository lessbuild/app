<?php

declare(strict_types=1);

namespace App\Enums;

enum IngestSource: string
{
    case Json = 'json';
    case OtlpTraces = 'otlp_traces';
    case OtlpLogs = 'otlp_logs';
    case OtlpMetrics = 'otlp_metrics';

    public function label(): string
    {
        return match ($this) {
            self::Json => 'JSON events',
            self::OtlpTraces => 'OTLP traces',
            self::OtlpLogs => 'OTLP logs',
            self::OtlpMetrics => 'OTLP metrics',
        };
    }

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
