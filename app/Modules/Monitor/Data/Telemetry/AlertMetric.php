<?php

namespace App\Modules\Monitor\Data\Telemetry;

enum AlertMetric: string
{
    case RequestErrorRate = 'request_error_rate';
    case RequestDuration = 'request_duration';
    case ExceptionCount = 'exception_count';
    case ErrorLogCount = 'error_log_count';
    case LogPatternCount = 'log_pattern_count';
    case TelemetryVolume = 'telemetry_volume';
    case TelemetryFreshness = 'telemetry_freshness';
    case SloBurnRate = 'slo_burn_rate';
    case NumericMetric = 'numeric_metric';
    case MetricAnomaly = 'metric_anomaly';

    public function label(): string
    {
        return match ($this) {
            self::RequestErrorRate => 'Request error rate (%)',
            self::RequestDuration => 'Average request duration (ms)',
            self::ExceptionCount => 'Exception count',
            self::ErrorLogCount => 'Error / critical log count',
            self::LogPatternCount => 'Matching log / event count',
            self::TelemetryVolume => 'Telemetry volume (events)',
            self::TelemetryFreshness => 'Telemetry freshness (seconds)',
            self::SloBurnRate => 'SLO error-budget burn rate',
            self::NumericMetric => 'Resource / custom numeric metric',
            self::MetricAnomaly => 'Metric anomaly score',
        };
    }

    public function maximum(): int
    {
        return match ($this) {
            self::RequestErrorRate => 100,
            self::RequestDuration => 86400000,
            self::TelemetryVolume => 1000000000,
            self::TelemetryFreshness => 86400000,
            self::SloBurnRate, self::LogPatternCount => 1000000,
            self::NumericMetric => 1000000000000000,
            self::MetricAnomaly => 20,
            default => 1000000000,
        };
    }

    public function isCount(): bool
    {
        return in_array($this, [self::ExceptionCount, self::ErrorLogCount, self::LogPatternCount, self::TelemetryVolume, self::TelemetryFreshness], true);
    }

    public function isTelemetryGuardrail(): bool
    {
        return in_array($this, [self::TelemetryVolume, self::TelemetryFreshness], true);
    }

    public function isSloBurnRate(): bool
    {
        return $this === self::SloBurnRate;
    }

    public function isAnomaly(): bool
    {
        return $this === self::MetricAnomaly;
    }
}
