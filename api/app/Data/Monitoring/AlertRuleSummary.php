<?php

declare(strict_types=1);

namespace App\Data\Monitoring;

use App\Models\AlertRule;

final readonly class AlertRuleSummary
{
    /**
     * Create a new AlertRuleSummary instance.
     *
     * An alert rule as lists show it.
     *
     * @param  int  $id
     * @param  string  $name
     * @param  string  $condition  Such as "Request error rate ≥ 5", in the person's language.
     * @param  int  $windowMinutes
     * @param  string  $environment
     * @param  bool  $enabled
     * @param  string  $state  healthy, breaching, no_data… (or paused, or archived).
     * @param  string  $stateLabel  The same, in the person's language.
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $condition,
        public int $windowMinutes,
        public string $environment,
        public bool $enabled,
        public string $state,
        public string $stateLabel,
    ) {}

    /**
     * Describe an alert rule (with its environment loaded).
     *
     * @param  AlertRule  $rule
     * @return self
     */
    public static function from(AlertRule $rule): self
    {
        $state = $rule->trashed() ? 'archived' : ($rule->enabled ? (string) $rule->evaluation_state : 'paused');

        return new self(
            id: $rule->id,
            name: $rule->name,
            condition: __($rule->metric->label()).' '.$rule->comparisonLabel().' '.$rule->thresholdValue(),
            windowMinutes: $rule->window_minutes,
            environment: $rule->environment->name,
            enabled: (bool) $rule->enabled,
            state: $state,
            stateLabel: match ($state) {
                'archived' => __('Archived'), 'paused' => __('Paused'), default => __(ucfirst(str_replace('_', ' ', $state)))
            },
        );
    }
}
