<?php

namespace App\Services;

use App\Models\MetricAlertRule;
use App\Models\ServerMetric;

class MetricAlertEvaluator
{
    /**
     * Bind incident notifications for metric threshold transitions.
     *
     * @param  IncidentNotifier  $notifier  Records and delivers failure or recovery events.
     */
    public function __construct(private readonly IncidentNotifier $notifier) {}

    /**
     * Update matching metric-rule breach counters and notify threshold or recovery transitions.
     *
     * @param  ServerMetric  $metric  The server sample used to evaluate enabled workspace and server-specific rules.
     * @return void No value; skips missing owners and nonnumeric metric values.
     */
    public function evaluate(ServerMetric $metric): void
    {
        $metric->loadMissing('server.organization');
        $server = $metric->server;
        if (! $server?->organization) {
            return;
        }
        MetricAlertRule::query()
            ->where('organization_id', $server->organization_id)
            ->where('is_enabled', true)
            ->where(fn ($query) => $query->whereNull('server_id')->orWhere('server_id', $server->id))
            ->each(function (MetricAlertRule $rule) use ($metric, $server): void {
                $value = $metric->getAttribute($rule->metric);
                if (! is_numeric($value)) {
                    return;
                }
                $breached = $rule->operator === 'lte'
                    ? (float) $value <= $rule->threshold
                    : (float) $value >= $rule->threshold;
                $breachCount = $breached ? min(255, $rule->breach_count + 1) : 0;
                $trigger = $breached
                    && $breachCount >= $rule->consecutive_breaches
                    && ! $rule->is_alerting
                    && (! $rule->last_triggered_at || $rule->last_triggered_at->lte(now()->subMinutes($rule->cooldown_minutes)));
                $recover = ! $breached && $rule->is_alerting;
                $rule->forceFill([
                    'breach_count' => $breachCount,
                    'is_alerting' => $trigger ? true : ($recover ? false : $rule->is_alerting),
                    'last_evaluated_at' => now(),
                    'last_triggered_at' => $trigger ? now() : $rule->last_triggered_at,
                ])->save();
                if ($trigger) {
                    $this->notifier->fail(
                        $server->organization->owner,
                        'metric',
                        $rule->id,
                        $rule->name,
                        "{$server->label}: {$rule->metric} is {$value} (threshold {$rule->operator} {$rule->threshold}).",
                    );
                } elseif ($recover) {
                    $this->notifier->recoverIfOpen(
                        $server->organization->owner,
                        'metric',
                        $rule->id,
                        $rule->name.' recovered',
                        "{$server->label}: {$rule->metric} returned to {$value}.",
                    );
                }
            });
    }
}
