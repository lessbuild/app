<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AlertRule;
use App\Models\Incident;
use App\Models\Monitor;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Incident> A monitor incident by default; `->for($rule)` or `forAlertRule()` makes an alert-rule incident. */
class IncidentFactory extends Factory
{
    protected $model = Incident::class;

    public function resolved(): static
    {
        return $this->state(fn (): array => ['active_slot' => null, 'status' => 'resolved', 'resolved_at' => now(), 'closure_reason' => 'recovered']);
    }

    public function forAlertRule(): static
    {
        return $this->state(fn (): array => ['alert_rule_id' => AlertRule::factory()->ready()]);
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'alert_rule_id' => null,
            'monitor_id' => fn (array $attributes): mixed => $attributes['alert_rule_id'] === null ? Monitor::factory() : null,
            'account_id' => fn (array $attributes): string => self::source($attributes)->environment->project->account_id,
            'project_id' => fn (array $attributes): string => self::source($attributes)->environment->project_id,
            'active_slot' => true, 'status' => 'open',
            'title' => fn (array $attributes): string => $attributes['alert_rule_id'] === null ? 'Public API health is down' : 'API error rate',
            'rule_snapshot' => fn (array $attributes): array => self::source($attributes)->snapshot(),
            'opening_observation' => fn (array $attributes): array => self::observation($attributes),
            'latest_observation' => fn (array $attributes): array => self::observation($attributes),
            'opened_at' => now(), 'last_breached_at' => now(),
        ];
    }

    /** @param array<string, mixed> $attributes */
    private static function source(array $attributes): Monitor|AlertRule
    {
        return $attributes['alert_rule_id'] !== null
            ? AlertRule::query()->whereKey($attributes['alert_rule_id'])->firstOrFail()
            : Monitor::query()->whereKey($attributes['monitor_id'])->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private static function observation(array $attributes): array
    {
        return $attributes['alert_rule_id'] !== null
            ? ['state' => 'breaching', 'value' => 100, 'samples' => 20, 'from' => now()->subMinutes(6)->toISOString(), 'until' => now()->subMinute()->toISOString()]
            : ['outcome' => 'down', 'reason' => 'unexpected_status', 'http_status' => 503, 'checked_at' => now()->toISOString()];
    }
}
