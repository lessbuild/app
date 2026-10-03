<?php

declare(strict_types=1);

namespace Tests\Feature\Telemetry;

use App\Actions\Telemetry\UpdateIssue;
use App\Actions\Telemetry\WakeSnoozedIssues;
use App\Enums\IssueStatus;
use App\Models\Environment;
use App\Models\IngestToken;
use App\Models\Issue;
use App\Models\IssueActivity;
use App\Models\TelemetryEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class IssueOccurrenceTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    public function test_new_exceptions_link_to_their_exact_issue_and_replays_do_not_duplicate_counts_or_activity(): void
    {
        $environment = $this->collector();
        $payload = ['batch_id' => 'one-delivery', 'events' => [
            ['id' => 'one', 'type' => 'exception', 'name' => 'First failure', 'fingerprint' => 'one'],
            ['id' => 'two', 'type' => 'exception', 'name' => 'Other failure', 'fingerprint' => 'two'],
            ['id' => 'three', 'type' => 'log', 'name' => 'Unrelated log', 'fingerprint' => 'one'],
        ]];

        $this->postJson(route('api.ingest'), $payload)->assertOk()->assertJsonPath('data.accepted', 3);
        $this->postJson(route('api.ingest'), $payload)->assertOk()->assertJsonPath('data.accepted', 0);

        $this->assertDatabaseCount('issues', 2);
        $this->assertDatabaseCount('issue_activities', 2);
        $this->assertDatabaseCount('telemetry_events', 3);
        $first = Issue::query()->where('fingerprint', 'one')->sole();
        $second = Issue::query()->where('fingerprint', 'two')->sole();
        $this->assertSame(1, $first->occurrences);
        $this->assertSame($environment->project_id, $first->project_id);
        $this->assertSame($first->id, TelemetryEvent::query()->where('name', 'First failure')->sole()->issue_id);
        $this->assertSame($second->id, TelemetryEvent::query()->where('name', 'Other failure')->sole()->issue_id);
        $this->assertNull(TelemetryEvent::query()->where('name', 'Unrelated log')->sole()->issue_id);
        $this->assertSame('detected', $first->activities()->sole()->action);
        $this->assertSame($first->telemetryEvents()->sole()->id, ($first->activities()->sole()->metadata ?? [])['event_id']);
    }

    public function test_same_fingerprint_groups_across_app_environments_without_crossing_applications(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-21T12:00:00Z'));
        $first = $this->collector();
        $issue = Issue::factory()->for($first->project)->for($first)->create([
            'fingerprint' => 'shared', 'first_seen_at' => '2026-09-21T11:00:00Z',
            'last_seen_at' => '2026-09-21T11:00:00Z', 'severity' => 'critical',
        ]);
        $other = Issue::factory()->create(['fingerprint' => 'shared']);
        $second = $this->collector(Environment::factory()->for($first->project)->create(['name' => 'Staging', 'slug' => 'staging']));

        $this->postJson(route('api.ingest'), ['batch_id' => 'issue-test', 'events' => [[
            'type' => 'exception', 'fingerprint' => 'shared', 'severity' => 'warning', 'timestamp' => '2026-09-21T10:00:00Z',
        ]]])->assertOk();

        $this->assertSame(2, $this->reload($issue)->occurrences);
        $this->assertSame($first->id, $this->reload($issue)->environment_id);
        $this->assertSame('critical', $this->reload($issue)->severity);
        $this->assertSame('2026-09-21 10:00:00', $this->reload($issue)->first_seen_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-21 11:00:00', $this->reload($issue)->last_seen_at->format('Y-m-d H:i:s'));
        $this->assertSame($second->id, TelemetryEvent::sole()->environment_id);
        $this->assertSame($issue->id, TelemetryEvent::sole()->issue_id);
        $this->assertSame(1, $this->reload($other)->occurrences);
        $this->assertDatabaseEmpty('issue_activities');
    }

    #[DataProvider('sourceTimes')]
    public function test_resolved_issues_reopen_only_after_a_new_source_failure(string $timestamp, string $expectedStatus, int $activities): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-21T12:00:00.500000Z'));
        $environment = $this->collector();
        $issue = Issue::factory()->for($environment->project)->resolved()->create([
            'fingerprint' => 'regression', 'resolved_at' => '2026-09-21T12:00:00.100000Z',
            'last_seen_at' => '2026-09-21T11:00:00Z', 'first_seen_at' => '2026-09-21T11:00:00Z',
            'state_version' => 4,
        ]);

        $this->postJson(route('api.ingest'), ['batch_id' => 'issue-test', 'events' => [[
            'type' => 'exception', 'fingerprint' => 'regression', 'timestamp' => $timestamp,
        ]]])->assertOk();

        $this->assertSame($expectedStatus, $this->reload($issue)->status->value);
        $this->assertSame(2, $this->reload($issue)->occurrences);
        $this->assertSame(4 + $activities, $this->reload($issue)->state_version);
        $this->assertDatabaseCount('issue_activities', $activities);
        $this->assertSame($issue->id, TelemetryEvent::sole()->issue_id);
        if ($activities === 1) {
            $this->assertSame('regressed', IssueActivity::sole()->action);
            $this->assertNull($this->reload($issue)->resolved_at);
        }
    }

    /**
     * @return array<array-key, list<mixed>>
     */
    public static function sourceTimes(): array
    {
        return [
            'new failure' => ['2026-09-21T12:00:00.200000Z', 'open', 1],
            'one microsecond after' => ['2026-09-21T12:00:00.100001Z', 'open', 1],
            'same instant' => ['2026-09-21T12:00:00.100000Z', 'resolved', 0],
            'older failure' => ['2026-09-21T11:59:59.999999Z', 'resolved', 0],
        ];
    }

    public function test_delayed_queued_delivery_does_not_undo_a_later_resolution_even_with_a_future_source_clock(): void
    {
        config(['monitoring.telemetry.queue_connection' => 'telemetry']);
        $this->travelTo(CarbonImmutable::parse('2026-09-21T12:00:00Z'));
        $environment = $this->collector();
        $issue = Issue::factory()->for($environment->project)->create(['fingerprint' => 'queued']);
        $this->postJson(route('api.ingest'), ['batch_id' => 'issue-test', 'events' => [[
            'type' => 'exception', 'fingerprint' => 'queued', 'timestamp' => '2026-09-21T12:02:00Z',
        ]]])->assertAccepted();
        $this->travel(1)->minutes();
        app(UpdateIssue::class)->handle($issue, $this->ownerOf($environment), ['action' => 'resolve', 'version' => 0]);
        $this->travel(2)->minutes();

        $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'telemetry', '--queue' => 'telemetry', '--once' => true, '--sleep' => 0, '--tries' => 5]));

        $this->assertSame(IssueStatus::Resolved, $this->reload($issue)->status);
        $this->assertSame(2, $this->reload($issue)->occurrences);
        $this->assertSame(1, $this->reload($issue)->state_version);
        $this->assertSame('resolve', IssueActivity::sole()->action);
        $this->assertSame($issue->id, TelemetryEvent::sole()->issue_id);
    }

    #[DataProvider('retainedStates')]
    public function test_occurrences_respect_ignored_and_snoozed_states(string $state, ?string $deadline, string $expectedStatus, ?string $activity): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-21T12:00:00Z'));
        $environment = $this->collector();
        $issue = Issue::factory()->for($environment->project)->create([
            'fingerprint' => 'retained', 'status' => $state, 'snoozed_until' => $deadline,
        ]);

        $this->postJson(route('api.ingest'), ['batch_id' => 'issue-test', 'events' => [['type' => 'exception', 'fingerprint' => 'retained']]])->assertOk();

        $this->assertSame($expectedStatus, $this->reload($issue)->status->value);
        $this->assertSame(2, $this->reload($issue)->occurrences);
        $this->assertSame($activity, $issue->activities()->first()?->action);
    }

    /**
     * @return array<array-key, list<mixed>>
     */
    public static function retainedStates(): array
    {
        return [
            'ignored' => ['ignored', null, 'ignored', null],
            'snoozed' => ['snoozed', '2026-09-21T13:00:00Z', 'snoozed', null],
            'deadline reached' => ['snoozed', '2026-09-21T12:00:00Z', 'open', 'snooze_expired'],
            'legacy snooze' => ['snoozed', null, 'snoozed', null],
        ];
    }

    public function test_legacy_resolved_issues_reopen_on_newer_occurrences_without_inventing_a_resolution_date(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-21T12:00:00Z'));
        $environment = $this->collector();
        $issue = Issue::factory()->for($environment->project)->create([
            'fingerprint' => 'legacy', 'status' => 'resolved', 'last_seen_at' => '2026-09-21T11:00:00Z',
        ]);

        $this->postJson(route('api.ingest'), ['batch_id' => 'issue-test', 'events' => [['type' => 'exception', 'fingerprint' => 'legacy']]])->assertOk();

        $this->assertSame(IssueStatus::Open, $this->reload($issue)->status);
        $this->assertSame('regressed', IssueActivity::sole()->action);
    }

    public function test_snooze_command_wakes_due_issues_once_in_bounded_order_without_new_telemetry(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-21T12:00:00Z'));
        $first = Issue::factory()->snoozed()->create(['snoozed_until' => now()->subMinutes(2)]);
        $second = Issue::factory()->for($first->project)->snoozed()->create(['snoozed_until' => now()]);
        $later = Issue::factory()->for($first->project)->snoozed()->create();

        $this->assertSame(0, Artisan::call('issues:wake', ['--limit' => 1]));
        $this->assertStringContainsString('Reopened 1 snoozed issues.', Artisan::output());

        $this->assertSame(IssueStatus::Open, $this->reload($first)->status);
        $this->assertSame(IssueStatus::Snoozed, $this->reload($second)->status);
        $this->assertSame(1, $this->reload($first)->state_version);
        $this->assertNull($this->reload($first)->snoozed_until);
        $this->assertSame(1, app(WakeSnoozedIssues::class)->handle());
        $this->assertSame(0, app(WakeSnoozedIssues::class)->handle());
        $this->assertSame(IssueStatus::Snoozed, $this->reload($later)->status);
        $this->assertDatabaseCount('issue_activities', 2);
        $this->assertDatabaseEmpty('telemetry_events');
    }

    #[DataProvider('invalidLimits')]
    public function test_snooze_command_rejects_invalid_limits_without_changes(string $limit): void
    {
        $issue = Issue::factory()->snoozed()->create(['snoozed_until' => now()->subHour()]);

        $this->assertSame(2, Artisan::call('issues:wake', ['--limit' => $limit]));
        $this->assertStringContainsString('The limit must be an integer between 1 and 1000.', Artisan::output());

        $this->assertSame(IssueStatus::Snoozed, $this->reload($issue)->status);
        $this->assertDatabaseEmpty('issue_activities');
    }

    /**
     * @return array<array-key, list<mixed>>
     */
    public static function invalidLimits(): array
    {
        return [['0'], ['1001'], ['many'], ['1.5']];
    }

    public function test_worker_failure_rolls_back_occurrence_link_counts_and_audit_together(): void
    {
        $environment = $this->collector();
        $this->rejectInserts('telemetry_usage_entries', 'reject_usage', 'meter unavailable');
        Exceptions::fake();

        $this->postJson(route('api.ingest'), ['batch_id' => 'issue-test', 'events' => [['type' => 'exception', 'name' => 'Rollback failure']]])->assertInternalServerError();

        Exceptions::assertReported(QueryException::class);
        foreach (['issues', 'issue_activities', 'telemetry_events', 'telemetry_usage_entries'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
        $this->assertSame(0, $this->reload($environment)->telemetry_event_count);
    }

    private function collector(?Environment $environment = null): Environment
    {
        $environment ??= Environment::factory()->create();
        $secret = 'issue-test-collector-'.$environment->id;
        IngestToken::factory()->for($environment)->withSecret($secret)->create();
        $this->withToken($secret);

        return $environment;
    }
}
