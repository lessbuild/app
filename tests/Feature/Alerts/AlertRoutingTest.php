<?php

declare(strict_types=1);

namespace Tests\Feature\Alerts;

use App\Actions\Monitoring\ArchiveAlertRule;
use App\Enums\AccountRole;
use App\Models\AlertDelivery;
use App\Models\AlertDestination;
use App\Models\AlertRule;
use App\Models\Incident;
use App\Models\TelemetryEvent;
use App\Models\User;
use App\Services\Monitoring\AlertDispatcher;
use App\Services\Monitoring\AlertRuleEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class AlertRoutingTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    public function test_saving_routing_preserves_evaluation_and_does_not_backfill_existing_incidents(): void
    {
        $rule = AlertRule::factory()->ready()->create(['breach_streak' => 1]);
        Incident::factory()->for($rule)->create();
        $destination = AlertDestination::factory()->for($rule->environment->project->account)->create();
        $since = $rule->monitoring_since;
        Http::preventStrayRequests();

        $this->actingAs($this->ownerOf($destination->account))->put(route('monitoring.rules.routing', [$rule->environment->project_id, $rule->id]), [
            'version' => 0, 'destinations' => [$destination->id], 'opened' => 1, 'recovered' => 0,
        ])->assertRedirect(route('monitoring.rules.show', [$rule->environment->project_id, $rule->id]));

        $this->assertSame(1, $this->reload($rule)->state_version);
        $this->assertSame(1, $this->reload($rule)->breach_streak);
        $this->assertTrue($since->equalTo($this->reload($rule)->monitoring_since));
        $this->assertDatabaseHas('alert_destination_alert_rule', ['alert_rule_id' => $rule->id, 'alert_destination_id' => $destination->id, 'opened' => 1, 'recovered' => 0]);
        $this->assertDatabaseEmpty('alert_deliveries');
        $this->assertDatabaseEmpty('jobs');
        Http::assertNothingSent();
    }

    public function test_unselecting_all_destinations_removes_routing(): void
    {
        [$incident, $destination, $rule] = $this->routed();

        $this->actingAs($this->ownerOf($destination->account))->put(route('monitoring.rules.routing', [$rule->environment->project_id, $rule->id]), [
            'version' => 0, 'opened' => 1, 'recovered' => 1,
        ])->assertRedirect();

        $this->assertDatabaseEmpty('alert_destination_alert_rule');
    }

    public function test_failed_routing_submission_keeps_cleared_destinations_unchecked(): void
    {
        [$incident, $destination, $rule] = $this->routed();
        $this->actingAs($this->ownerOf($destination->account));

        $this->from(route('monitoring.rules.show', [$rule->environment->project_id, $rule->id]))->put(route('monitoring.rules.routing', [$rule->environment->project_id, $rule->id]), [
            'version' => 0, 'opened' => 1, 'recovered' => 'invalid',
        ])->assertRedirect(route('monitoring.rules.show', [$rule->environment->project_id, $rule->id]))
            ->assertSessionHasErrors(['recovered' => 'The recovered field must be true or false.']);

        $response = $this->withCookie(session()->getName(), session()->getId())->get(route('monitoring.rules.show', [$rule->environment->project_id, $rule->id]));
        $response->assertSee('aria-describedby="recovered-error"', false);
        $this->assertSame(1, $this->xpathCount($response, '//input[@name="destinations[]"][not(@checked)]'));
        $this->assertSame([$destination->id], $rule->destinations()->pluck('alert_destinations.id')->all());
        $this->assertSame(0, $this->reload($rule)->state_version);
        $this->assertDatabaseEmpty('alert_deliveries');
        $this->assertDatabaseEmpty('jobs');
    }

    public function test_failed_escalation_submission_keeps_saved_routing_selected(): void
    {
        [$incident, $destination, $rule] = $this->routed();
        $this->onMonitoringTier($destination->account, 'pro');
        $this->actingAs($this->ownerOf($destination->account));

        $this->from(route('monitoring.rules.show', [$rule->environment->project_id, $rule->id]))->put(route('monitoring.rules.escalations', [$rule->environment->project_id, $rule->id]), [
            'version' => 0, 'escalations' => [['destination_id' => $destination->id, 'delay_minutes' => 0]],
        ])->assertRedirect(route('monitoring.rules.show', [$rule->environment->project_id, $rule->id]))->assertSessionHasErrors('escalations.0.delay_minutes');

        $response = $this->withCookie(session()->getName(), session()->getId())->get(route('monitoring.rules.show', [$rule->environment->project_id, $rule->id]));
        $response->assertSee('id="escalation-0-delay-error"', false);
        $this->assertSame(1, $this->xpathCount($response, '//input[@name="destinations[]"][@checked]'));
        $this->assertSame([$destination->id], $rule->destinations()->pluck('alert_destinations.id')->all());
        $this->assertSame(0, $this->reload($rule)->state_version);
        $this->assertDatabaseEmpty('alert_escalations');
        $this->assertDatabaseEmpty('alert_deliveries');
        $this->assertDatabaseEmpty('jobs');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('invalidRouting')]
    public function test_validates_routing_values(array $data, string $field): void
    {
        [$incident, $destination, $rule] = $this->routed();

        $this->actingAs($this->ownerOf($destination->account))->putJson(route('monitoring.rules.routing', [$rule->environment->project_id, $rule->id]), [
            'version' => 0, 'opened' => 1, 'recovered' => 1, ...$data,
        ])->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertSame(0, $this->reload($rule)->state_version);
        $this->assertDatabaseEmpty('alert_deliveries');
    }

    /**
     * @return array<array-key, array<int, mixed>>
     */
    public static function invalidRouting(): array
    {
        return [
            'too many' => [['destinations' => [1, 2, 3, 4, 5, 6]], 'destinations'],
            'non-integer' => [['destinations' => ['wrong']], 'destinations.0'],
            'duplicate' => [['destinations' => [1, 1]], 'destinations.0'],
            'wrong boolean' => [['opened' => 'yes'], 'opened'],
            'negative version' => [['version' => -1], 'version'],
        ];
    }

    public function test_foreign_destinations_are_rejected_without_revealing_their_names(): void
    {
        [$incident, $destination, $rule] = $this->routed();
        $foreign = AlertDestination::factory()->create(['name' => 'Secret foreign destination']);

        $this->actingAs($this->ownerOf($destination->account))->putJson(route('monitoring.rules.routing', [$rule->environment->project_id, $rule->id]), [
            'version' => 0, 'opened' => 1, 'recovered' => 1, 'destinations' => [$foreign->id],
        ])->assertUnprocessable()->assertJsonValidationErrors(['destinations' => 'Choose up to five destinations from this workspace.'])->assertDontSee($foreign->name);

        $this->assertSame([$destination->id], $rule->destinations()->pluck('alert_destinations.id')->all());
    }

    public function test_stale_edits_and_non_admins_cannot_change_routes(): void
    {
        [$incident, $destination, $rule] = $this->routed();
        $data = ['version' => 99, 'opened' => 1, 'recovered' => 1];
        $this->actingAs($this->ownerOf($destination->account))->put(route('monitoring.rules.routing', [$rule->environment->project_id, $rule->id]), $data)->assertConflict();
        $member = User::factory()->create();
        $this->addMember($destination->account, $member, AccountRole::Viewer);

        $this->actingAs($member)->put(route('monitoring.rules.routing', [$rule->environment->project_id, $rule->id]), $data)->assertForbidden();

        $this->assertDatabaseCount('alert_destination_alert_rule', 1);
    }

    public function test_incident_transitions_write_one_outbox_entry_per_event_without_sending_network_requests(): void
    {
        [$incident, $destination, $rule] = $this->routed();
        Http::preventStrayRequests();

        DB::transaction(function () use ($incident): void {
            app(AlertDispatcher::class)->record($incident, 'opened');
            app(AlertDispatcher::class)->record($incident, 'opened');
        });

        $delivery = AlertDelivery::query()->sole();
        $this->assertSame($incident->id, $delivery->incident_id);
        $this->assertSame($destination->id, $delivery->alert_destination_id);
        $this->assertSame('opened', $delivery->payload['event']);
        $this->assertDatabaseCount('jobs', 1);
        $job = DB::table('jobs')->first();
        $this->assertNotNull($job);
        $this->assertSame($job->job_uuid, $delivery->queue_job_uuid);
        $this->assertStringNotContainsString((string) $destination->endpoint_url, $job->payload);
        $this->assertStringNotContainsString((string) $destination->signing_secret, $job->payload);
        $this->assertStringNotContainsString($incident->title, DB::table('alert_deliveries')->value('payload'));
        Http::assertNothingSent();
    }

    public function test_rollback_removes_both_outbox_and_database_job(): void
    {
        [$incident] = $this->routed();
        try {
            DB::transaction(function () use ($incident): void {
                app(AlertDispatcher::class)->record($incident, 'opened');
                throw new RuntimeException('Roll back the incident transaction.');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Roll back the incident transaction.', $exception->getMessage());
        }

        $this->assertDatabaseEmpty('alert_deliveries');

        $this->assertDatabaseEmpty('jobs');
    }

    public function test_outbox_ignores_corrupt_cross_tenant_routes_and_disabled_destinations(): void
    {
        [$incident, $destination, $rule] = $this->routed();
        $foreign = AlertDestination::factory()->create();
        $rule->destinations()->attach($foreign, ['opened' => true, 'recovered' => true]);
        $destination->forceFill(['enabled' => false])->save();

        DB::transaction(fn () => app(AlertDispatcher::class)->record($incident, 'opened'));

        $this->assertDatabaseEmpty('alert_deliveries');

        $this->assertDatabaseEmpty('jobs');
    }

    public function test_rule_archival_does_not_send_a_recovery_notification(): void
    {
        [$incident, $destination, $rule] = $this->routed();

        app(ArchiveAlertRule::class)->handle($rule, $this->ownerOf($destination->account), 0);

        $this->assertSame('rule_archived', $this->reload($incident)->closure_reason);
        $this->assertDatabaseEmpty('alert_deliveries');
    }

    public function test_evaluator_queues_open_and_recovery_transitions_once(): void
    {
        $this->travelTo('2026-09-21 12:00:00 UTC');
        $rule = AlertRule::factory()->ready()->create(['window_minutes' => 1, 'minimum_samples' => 1, 'trigger_checks' => 1, 'recovery_checks' => 1]);
        $destination = AlertDestination::factory()->for($rule->environment->project->account)->create();
        $rule->destinations()->attach($destination, ['opened' => true, 'recovered' => true]);
        TelemetryEvent::factory()->for($rule->environment)->create(['type' => 'request', 'severity' => 'error', 'status_code' => 500, 'occurred_at' => now()->subSeconds(90)]);

        app(AlertRuleEvaluator::class)->evaluate();
        $this->travel(1)->minutes();
        TelemetryEvent::factory()->for($rule->environment)->create(['type' => 'request', 'severity' => 'info', 'status_code' => 200, 'occurred_at' => now()->subSeconds(90)]);
        app(AlertRuleEvaluator::class)->evaluate();
        app(AlertRuleEvaluator::class)->evaluate();

        $this->assertSame(['opened', 'recovered'], AlertDelivery::query()->orderBy('id')->pluck('event')->all());
        $this->assertDatabaseCount('jobs', 2);
        $this->assertSame('recovered', Incident::query()->sole()->closure_reason);
    }

    public function test_rule_page_exposes_routing_only_to_administrators(): void
    {
        [$incident, $destination, $rule] = $this->routed();

        $this->actingAs($this->ownerOf($destination->account))->get(route('monitoring.rules.show', [$rule->environment->project_id, $rule->id]))->assertSee('Where alerts go')->assertSee($destination->name);
        $viewer = User::factory()->create();
        $this->addMember($destination->account, $viewer, AccountRole::Viewer);
        $this->actingAs($viewer)->get(route('monitoring.rules.show', [$rule->environment->project_id, $rule->id]))->assertDontSee('Where alerts go')->assertDontSee($destination->name);
    }

    /**
     * @return array{Incident, AlertDestination, AlertRule}
     */
    private function routed(): array
    {
        $rule = AlertRule::factory()->ready()->create();
        $incident = Incident::factory()->for($rule)->create();
        $destination = AlertDestination::factory()->for($rule->environment->project->account)->create();
        $rule->destinations()->attach($destination, ['opened' => true, 'recovered' => true]);

        return [$incident, $destination, $rule];
    }
}
