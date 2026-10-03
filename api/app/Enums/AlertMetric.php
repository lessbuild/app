<?php

declare(strict_types=1);

namespace App\Enums;

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

    /**
     * Get the metric's name on the alert rule form, with its unit.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::RequestErrorRate => __('Request error rate (%)'),
            self::RequestDuration => __('Average request duration (ms)'),
            self::ExceptionCount => __('Exception count'),
            self::ErrorLogCount => __('Error / critical log count'),
            self::LogPatternCount => __('Matching log / event count'),
            self::TelemetryVolume => __('Telemetry volume (events)'),
            self::TelemetryFreshness => __('Telemetry freshness (seconds)'),
            self::SloBurnRate => __('SLO error-budget burn rate'),
            self::NumericMetric => __('Resource / custom numeric metric'),
            self::MetricAnomaly => __('Metric anomaly score'),
        };
    }

    /**
     * Get the largest threshold the rule form accepts for this metric, in the metric's own unit.
     *
     * @return int
     */
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

    /**
     * Determine whether thresholds must be whole numbers: counts of events and freshness in seconds can't be
     * fractional, unlike rates and durations.
     *
     * @return bool
     */
    public function hasWholeNumberThreshold(): bool
    {
        return in_array($this, [self::ExceptionCount, self::ErrorLogCount, self::LogPatternCount, self::TelemetryVolume, self::TelemetryFreshness], true);
    }

    /**
     * Determine whether the metric watches the telemetry pipeline itself (volume and freshness) rather than the
     * application. These need the guardrails plan feature.
     *
     * @return bool
     */
    public function isTelemetryGuardrail(): bool
    {
        return in_array($this, [self::TelemetryVolume, self::TelemetryFreshness], true);
    }

    /**
     * Determine whether the rule measures how fast an SLO's error budget is burning, which needs the SLO burn-rate
     * plan feature.
     *
     * @return bool
     */
    public function isSloBurnRate(): bool
    {
        return $this === self::SloBurnRate;
    }

    /**
     * Determine whether the rule fires on anomaly scores instead of a fixed threshold, which needs the anomaly plan
     * feature.
     *
     * @return bool
     */
    public function isAnomaly(): bool
    {
        return $this === self::MetricAnomaly;
    }
}
