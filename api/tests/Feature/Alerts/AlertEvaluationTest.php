<?php

declare(strict_types=1);

namespace Tests\Feature\Alerts;

use App\Models\AlertRule;
use App\Models\Environment;
use App\Models\Incident;
use App\Models\IngestToken;
use App\Models\TelemetryEvent;
use App\Services\Monitoring\AlertRuleEvaluator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class AlertEvaluationTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Consecutive breaches open one incident and repeated runs are idempotent.
     */
    public function test_consecutive_breaches_open_one_incident_and_repeated_runs_are_idempotent(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 21)->setTime(12, 0));
        $rule = AlertRule::factory()->ready()->create(['minimum_samples' => 1]);
        $this->event($rule, ['status_code' => 503]);

        $this->command('alerts:evaluate')->expectsOutput('Evaluated 1 alert rules.')->assertSuccessful();

        $this->assertDatabaseEmpty('incidents');
        $this->assertSame(1, $this->reload($rule)->breach_streak);
        $this->assertSame(0, $this->app->make(AlertRuleEvaluator::class)->evaluate());
        $this->travel(1)->minutes();
        $this->app->make(AlertRuleEvaluator::class)->evaluate();
        $incident = Incident::query()->sole();
        $this->assertSame('open', $incident->status);
        $this->assertSame($rule->id, $incident->alert_rule_id);
        $this->assertSame(100.0, (float) $incident->opening_observation['value']);
        $this->assertSame('request_error_rate', $incident->rule_snapshot['metric']);
        $this->assertSame(['opened'], $incident->activities()->pluck('action')->all());

        $this->travel(1)->minutes();
        $this->app->make(AlertRuleEvaluator::class)->evaluate();
        $this->assertDatabaseCount('incidents', 1);
        $this->assertDatabaseCount('incident_activities', 1);
        $this->assertSame('12:02', $this->reload($incident)->last_breached_at->format('H:i'));
    }

    /**
     * No data does not recover and breaks the recovery streak.
     */
    public function test_no_data_does_not_recover_and_breaks_the_recovery_streak(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $rule = AlertRule::factory()->ready()->create(['minimum_samples' => 1, 'window_minutes' => 1]);
        $incident = Incident::factory()->for($rule)->create();
        $this->event($rule);

        $this->app->make(AlertRuleEvaluator::class)->evaluate();

        $this->assertSame(1, $this->reload($rule)->recovery_streak);
        $this->travel(1)->minutes();
        $this->app->make(AlertRuleEvaluator::class)->evaluate();
        $this->assertSame('no_data', $this->reload($rule)->evaluation_state);
        $this->assertSame(0, $this->reload($rule)->recovery_streak);
        $this->assertSame('open', $this->reload($incident)->status);

        $this->travel(1)->minutes();
        $this->event($rule);
        $this->app->make(AlertRuleEvaluator::class)->evaluate();
        $this->assertDatabaseHas('incidents', ['id' => $incident->id, 'status' => 'open']);
        $this->travel(1)->minutes();
        $this->event($rule);
        $this->app->make(AlertRuleEvaluator::class)->evaluate();
        $this->assertSame('resolved', $this->reload($incident)->status);
        $this->assertSame('recovered', $this->reload($incident)->closure_reason);
        $this->assertNull($this->reload($incident)->active_slot);
        $this->assertSame(['recovered'], $incident->activities()->pluck('action')->all());
    }

    /**
     * A new breach after recovery creates a separate incident with immutable evidence.
     */
    public function test_a_new_breach_after_recovery_creates_a_separate_incident_with_immutable_evidence(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $rule = AlertRule::factory()->ready()->create(['minimum_samples' => 1, 'window_minutes' => 1, 'trigger_checks' => 1, 'recovery_checks' => 1]);
        $this->event($rule, ['status_code' => 500]);
        $this->app->make(AlertRuleEvaluator::class)->evaluate();
        $first = Incident::query()->sole();

        $this->travel(1)->minutes();
        $this->event($rule);
        $this->app->make(AlertRuleEvaluator::class)->evaluate();
        $this->travel(1)->minutes();
        $this->event($rule, ['severity' => 'critical']);
        $this->app->make(AlertRuleEvaluator::class)->evaluate();

        $this->assertDatabaseCount('incidents', 2);
        $this->assertSame('recovered', $this->reload($first)->closure_reason);
        $this->assertSame(100, $this->reload($first)->opening_observation['value']);
        $this->assertSame(1, Incident::query()->where('active_slot', true)->count());
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    #[DataProvider('metrics')]
    /**
     * Metric rules measure only eligible samples.
     */
    public function test_metric_rules_measure_only_eligible_samples(string $metric, array $events, int $samples, ?float $value, string $state): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $rule = AlertRule::factory()->ready()->create(['metric' => $metric, 'minimum_samples' => 1, 'threshold' => 50, 'trigger_checks' => 1]);
        foreach ($events as $attributes) {
            $this->event($rule, $attributes);
        }

        $this->app->make(AlertRuleEvaluator::class)->evaluate();

        $observed = $this->observed($rule, 'value');
        $this->assertSame($samples, $this->observed($rule, 'samples'));
        $this->assertSame($value, is_numeric($observed) ? (float) $observed : null);
        $this->assertSame($state, $this->observed($rule, 'state'));
    }

    /**
     * @return array<array-key, array<int, mixed>>
     */
    public static function metrics(): array
    {
        return [
            'error rate excludes non requests and counts 5xx once' => ['request_error_rate', [['status_code' => 599, 'severity' => 'critical'], ['status_code' => 400], ['type' => 'exception'], ['type' => 'log', 'severity' => 'error']], 2, 50.0, 'breaching'],
            'severity can flag a request without status' => ['request_error_rate', [['status_code' => null, 'severity' => 'error'], ['status_code' => 600]], 2, 50.0, 'breaching'],
            'duration excludes null negative and query timings' => ['request_duration', [['duration_ms' => 100], ['duration_ms' => 0], ['duration_ms' => null], ['duration_ms' => -20], ['type' => 'query', 'duration_ms' => 5000]], 2, 50.0, 'breaching'],
            'exceptions use all matching events as activity samples' => ['exception_count', [['type' => 'exception'], ['type' => 'exception'], ['type' => 'log', 'severity' => 'error']], 3, 2.0, 'healthy'],
            'logs include error and critical only' => ['error_log_count', [['type' => 'log', 'severity' => 'error'], ['type' => 'log', 'severity' => 'critical'], ['type' => 'log', 'severity' => 'warning'], ['type' => 'exception', 'severity' => 'error']], 4, 2.0, 'healthy'],
            'no requests is unknown' => ['request_error_rate', [['type' => 'log']], 0, null, 'no_data'],
            'no timed requests is unknown' => ['request_duration', [['duration_ms' => null]], 0, null, 'no_data'],
            'zero count without activity is unknown' => ['exception_count', [], 0, 0.0, 'no_data'],
            'zero error count with activity is measured' => ['error_log_count', [[]], 1, 0.0, 'healthy'],
        ];
    }

    /**
     * Windows are source time scoped half open and include legacy second boundaries.
     */
    public function test_windows_are_source_time_scoped_half_open_and_include_legacy_second_boundaries(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 21)->setTime(12, 0));
        $rule = AlertRule::factory()->ready()->create(['service' => '0', 'minimum_samples' => 1, 'trigger_checks' => 1]);
        $boundary = $this->event($rule, ['service' => '0', 'status_code' => 503, 'occurred_at' => '2026-09-21 11:54:00']);
        DB::table('telemetry_events')->where('id', $boundary->id)->update(['occurred_at' => '2026-09-21 11:54:00']);
        $this->event($rule, ['service' => '0', 'occurred_at' => '2026-09-21 11:58:59.999999']);
        foreach (['2026-09-21 11:53:59.999999', '2026-09-21 11:59:00', '2026-09-21 12:05:00'] as $time) {
            $this->event($rule, ['service' => '0', 'occurred_at' => $time]);
        }
        $this->event($rule, ['service' => 'other']);
        TelemetryEvent::factory()->create(['service' => '0', 'occurred_at' => now()->subMinutes(2)]);

        $this->app->make(AlertRuleEvaluator::class)->evaluate();

        $this->assertSame(2, $this->observed($rule, 'samples'));
        $this->assertSame(50, $this->observed($rule, 'value'));
        $this->assertSame('2026-09-21T11:54:00.000000Z', $this->observed($rule, 'from'));
        $this->assertSame('2026-09-21T11:59:00.000000Z', $this->observed($rule, 'until'));
    }

    /**
     * Insufficient samples break a breach streak without opening an incident.
     */
    public function test_insufficient_samples_break_a_breach_streak_without_opening_an_incident(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $rule = AlertRule::factory()->ready()->create(['minimum_samples' => 2, 'breach_streak' => 1, 'evaluated_until' => now()->subMinutes(2)]);
        $this->event($rule, ['status_code' => 500]);

        $this->app->make(AlertRuleEvaluator::class)->evaluate();

        $this->assertSame('no_data', $this->reload($rule)->evaluation_state);
        $this->assertSame(0, $this->reload($rule)->breach_streak);
        $this->assertDatabaseEmpty('incidents');
    }

    /**
     * New rules wait for a full window and settling delay.
     */
    public function test_new_rules_wait_for_a_full_window_and_settling_delay(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $rule = AlertRule::factory()->create(['minimum_samples' => 1, 'window_minutes' => 1, 'trigger_checks' => 1]);
        $this->travel(1)->minutes();
        $this->app->make(AlertRuleEvaluator::class)->evaluate();
        $this->assertSame('warming', $this->reload($rule)->evaluation_state);
        $this->assertDatabaseEmpty('incidents');

        $this->travel(1)->minutes();
        $this->event($rule, ['status_code' => 500]);
        $this->app->make(AlertRuleEvaluator::class)->evaluate();

        $this->assertSame('breaching', $this->reload($rule)->evaluation_state);
        $this->assertDatabaseCount('incidents', 1);
    }

    /**
     * Skipped minutes break consecutiveness without replaying unobserved checks.
     */
    public function test_skipped_minutes_break_consecutiveness_without_replaying_unobserved_checks(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $rule = AlertRule::factory()->ready()->create(['minimum_samples' => 1]);
        $this->event($rule, ['status_code' => 500]);
        $this->app->make(AlertRuleEvaluator::class)->evaluate();

        $this->travel(2)->minutes();
        $this->app->make(AlertRuleEvaluator::class)->evaluate();

        $this->assertSame(1, $this->reload($rule)->breach_streak);
        $this->assertDatabaseEmpty('incidents');
    }

    #[DataProvider('inactiveSources')]
    /**
     * Inactive sources or rules are not evaluated.
     */
    public function test_inactive_sources_or_rules_are_not_evaluated(string $state): void
    {
        $rule = AlertRule::factory()->ready()->create();
        $incident = Incident::factory()->for($rule)->create();
        match ($state) {
            'paused_rule' => $rule->update(['enabled' => false]),
            'archived_rule' => $rule->delete(),
            'paused_environment' => $rule->environment->project->enabledServices()->where('service', 'monitoring')->delete(),
            default => $this->fail("Unknown state {$state}."),
        };

        $this->assertSame(0, $this->app->make(AlertRuleEvaluator::class)->evaluate());

        $this->assertSame('open', $this->reload($incident)->status);
        $this->assertDatabaseCount('incident_activities', 0);
    }

    /**
     * @return array<array-key, array<int, mixed>>
     */
    public static function inactiveSources(): array
    {
        return array_combine(['paused_rule', 'archived_rule', 'paused_environment'], array_map(fn ($state): array => [$state], ['paused_rule', 'archived_rule', 'paused_environment']));
    }

    /**
     * Bounded evaluation processes oldest due rules without starving the rest.
     */
    public function test_bounded_evaluation_processes_oldest_due_rules_without_starving_the_rest(): void
    {
        $this->freezeTime();
        $first = AlertRule::factory()->ready()->create(['next_evaluation_at' => now()->subMinutes(3)]);
        $second = AlertRule::factory()->ready()->create(['next_evaluation_at' => now()->subMinutes(2)]);
        $third = AlertRule::factory()->ready()->create(['next_evaluation_at' => now()->subMinute()]);

        $this->command('alerts:evaluate --limit=2')->expectsOutput('Evaluated 2 alert rules.')->assertSuccessful();

        $this->assertNotNull($this->reload($first)->checked_at);
        $this->assertNotNull($this->reload($second)->checked_at);
        $this->assertNull($this->reload($third)->checked_at);
        $this->assertSame(1, $this->app->make(AlertRuleEvaluator::class)->evaluate(2));
        $this->assertNotNull($this->reload($third)->checked_at);
    }

    #[DataProvider('invalidLimits')]
    /**
     * Invalid command limits make no changes.
     */
    public function test_invalid_command_limits_make_no_changes(string $limit): void
    {
        $rule = AlertRule::factory()->ready()->create();

        $this->command('alerts:evaluate --limit='.$limit)->expectsOutput('The limit must be an integer between 1 and 1000.')->assertExitCode(2);

        $this->assertNull($this->reload($rule)->checked_at);
    }

    /**
     * @return array<array-key, array<int, mixed>>
     */
    public static function invalidLimits(): array
    {
        return [['0'], ['1001'], ['-1'], ['1.5'], ['bad']];
    }

    /**
     * Incident audit failure rolls back evaluation so the check can retry.
     */
    public function test_incident_audit_failure_rolls_back_evaluation_so_the_check_can_retry(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $rule = AlertRule::factory()->ready()->create(['minimum_samples' => 1, 'trigger_checks' => 1]);
        $this->event($rule, ['status_code' => 503]);
        $this->rejectInserts('incident_activities', 'reject_alert_activity', 'audit unavailable');

        try {
            $this->app->make(AlertRuleEvaluator::class)->evaluate();
            $this->fail('Audit failure should abort the check.');
        } catch (QueryException $exception) {
            $this->assertNull($this->reload($rule)->checked_at);
            $this->assertNull($this->reload($rule)->evaluated_until);
            $this->assertDatabaseEmpty('incidents');
            $this->assertDatabaseEmpty('incident_activities');
        }
    }

    /**
     * Backward clock does not replay an already evaluated window.
     */
    public function test_backward_clock_does_not_replay_an_already_evaluated_window(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $rule = AlertRule::factory()->ready()->create(['minimum_samples' => 1, 'trigger_checks' => 1]);
        $this->event($rule, ['status_code' => 503]);
        $this->app->make(AlertRuleEvaluator::class)->evaluate();
        $before = $this->reload($rule)->observation;
        $rule->forceFill(['next_evaluation_at' => now()->subMinutes(10)])->save();
        $this->travel(-1)->minutes();

        $this->assertSame(0, $this->app->make(AlertRuleEvaluator::class)->evaluate());

        $this->assertSame($before, $this->reload($rule)->observation);
        $this->assertDatabaseCount('incidents', 1);
        $this->assertDatabaseCount('incident_activities', 1);
    }

    /**
     * A created rule evaluates ingested telemetry and exposes the incident in the inbox.
     */
    public function test_a_created_rule_evaluates_ingested_telemetry_and_exposes_the_incident_in_the_inbox(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 21)->setTime(12, 0));
        $environment = Environment::factory()->create();
        $owner = $this->ownerOf($environment->project->account);
        IngestToken::factory()->for($environment)->withSecret('alert-integration-token')->create();
        $this->actingAs($owner)->postRule([
            'name' => 'API failures', 'environment_id' => $environment->id, 'metric' => 'request_error_rate',
            'service' => 'api', 'threshold' => 50, 'window_minutes' => 1, 'minimum_samples' => 2,
            'trigger_checks' => 1, 'recovery_checks' => 1, 'enabled' => 1,
        ])->assertSuccessful();
        $this->travel(30)->seconds();
        $this->withToken('alert-integration-token')->postJson(route('api.ingest'), ['batch_id' => 'incident-integration', 'events' => [
            ['type' => 'request', 'name' => 'GET /checkout', 'service' => 'api', 'status_code' => 503, 'timestamp' => now()->toISOString()],
            ['type' => 'request', 'name' => 'GET /catalog', 'service' => 'api', 'status_code' => 200, 'timestamp' => now()->toISOString()],
        ]])->assertOk()->assertJsonPath('data.accepted', 2);
        $this->travel(90)->seconds();

        $this->command('alerts:evaluate')->expectsOutput('Evaluated 1 alert rules.')->assertSuccessful();

        $incident = Incident::query()->sole();
        $this->assertSame(50, $incident->opening_observation['value']);
        $this->assertSame(2, $incident->opening_observation['samples']);
        $this->getJson((route('app.monitoring.incidents', $environment->project_id)))->assertJsonPath('incidents.0.id', $incident->id)->assertJsonPath('incidents.0.title', 'API failures');
    }

    /** @param array<string, mixed> $attributes */
    private function event(AlertRule $rule, array $attributes = []): TelemetryEvent
    {
        return TelemetryEvent::factory()->for($rule->environment)->create(array_replace(['occurred_at' => now()->subSeconds(90)], $attributes));
    }
}
