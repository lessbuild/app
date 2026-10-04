<?php

declare(strict_types=1);

namespace Tests\Feature\Alerts;

use App\Contracts\Monitoring\DnsResolver;
use App\Enums\AccountRole;
use App\Enums\AlertDeliveryStatus;
use App\Models\AlertDelivery;
use App\Models\AlertDestination;
use App\Models\AlertEscalation;
use App\Models\AlertRule;
use App\Models\Incident;
use App\Models\User;
use App\Services\Monitoring\AlertDeliveryRunner;
use App\Services\Monitoring\AlertDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class AlertEscalationTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Nested escalation errors name the step's field.
     */
    public function test_nested_escalation_errors_name_the_steps_field(): void
    {
        $rule = AlertRule::factory()->ready()->create();
        $account = $rule->environment->project->account;
        $this->onMonitoringTier($account, 'pro');
        $destination = AlertDestination::factory()->for($account)->create(['name' => 'On-call']);
        $this->actingAs($this->ownerOf($account));

        $this->putJson((route('app.monitoring.rules.escalations', [$rule->environment->project_id, $rule->id])), [
            'version' => 0, 'escalations' => [['destination_id' => $destination->id, 'delay_minutes' => 0]],
        ])->assertJsonValidationErrors('escalations.0.delay_minutes');

        $this->assertDatabaseEmpty('alert_escalations');
        $this->assertSame(0, $this->reload($rule)->state_version);
    }

    /**
     * Editor preserves valid custom delays created through the api.
     */
    public function test_editor_preserves_valid_custom_delays_created_through_the_api(): void
    {
        $rule = AlertRule::factory()->ready()->create();
        $account = $rule->environment->project->account;
        $this->onMonitoringTier($account, 'pro');
        $destination = AlertDestination::factory()->for($account)->create();
        $this->actingAs($this->ownerOf($account));

        $this->putJson(route('app.monitoring.rules.escalations', [$rule->environment->project_id, $rule->id]), [
            'version' => 0, 'escalations' => [['destination_id' => $destination->id, 'delay_minutes' => 7]],
        ])->assertJsonRedirect(route('monitoring.rules.show', [$rule->environment->project_id, $rule->id]));

        $this->getJson(route('app.monitoring.rules.show', [$rule->environment->project_id, $rule->id]))
            ->assertJsonPath('escalations', [['destinationId' => $destination->id, 'delayMinutes' => 7]]);
        $this->assertDatabaseHas('alert_escalations', ['alert_rule_id' => $rule->id, 'delay_minutes' => 7]);
    }

    /**
     * Pro owner can save ordered escalation steps.
     */
    public function test_pro_owner_can_save_ordered_escalation_steps(): void
    {
        $rule = AlertRule::factory()->ready()->create();
        $account = $rule->environment->project->account;
        $this->onMonitoringTier($account, 'pro');
        $first = AlertDestination::factory()->for($account)->create(['name' => 'On-call']);
        $second = AlertDestination::factory()->for($account)->create(['name' => 'Engineering lead']);

        $this->actingAs($this->ownerOf($account))->putJson(route('app.monitoring.rules.escalations', [$rule->environment->project_id, $rule->id]), [
            'version' => 0,
            'escalations' => [
                ['destination_id' => $first->id, 'delay_minutes' => 5],
                ['destination_id' => $second->id, 'delay_minutes' => 30],
            ],
        ])->assertJsonRedirect(route('monitoring.rules.show', [$rule->environment->project_id, $rule->id]));

        $this->assertSame(1, $this->reload($rule)->state_version);
        $this->assertDatabaseHas('alert_escalations', ['alert_rule_id' => $rule->id, 'alert_destination_id' => $first->id, 'position' => 0, 'delay_minutes' => 5]);
        $this->assertDatabaseHas('alert_escalations', ['alert_rule_id' => $rule->id, 'alert_destination_id' => $second->id, 'position' => 1, 'delay_minutes' => 30]);
    }

    /**
     * Free plan cannot save escalation steps.
     */
    public function test_free_plan_cannot_save_escalation_steps(): void
    {
        $rule = AlertRule::factory()->ready()->create();
        $account = $rule->environment->project->account;
        $destination = AlertDestination::factory()->for($account)->create();

        $this->actingAs($this->ownerOf($account))->putJson(route('app.monitoring.rules.escalations', [$rule->environment->project_id, $rule->id]), [
            'version' => 0,
            'escalations' => [['destination_id' => $destination->id, 'delay_minutes' => 5]],
        ])->assertUnprocessable()->assertJsonValidationErrors('escalations');

        $this->assertDatabaseEmpty('alert_escalations');
        $this->assertSame(0, $this->reload($rule)->state_version);
    }

    /**
     * Escalation validation rejects primary destinations and non increasing delays.
     */
    public function test_escalation_validation_rejects_primary_destinations_and_non_increasing_delays(): void
    {
        $rule = AlertRule::factory()->ready()->create();
        $account = $rule->environment->project->account;
        $this->onMonitoringTier($account, 'pro');
        $primary = AlertDestination::factory()->for($account)->create();
        $secondary = AlertDestination::factory()->for($account)->create();
        $rule->destinations()->attach($primary, ['opened' => true, 'recovered' => true]);

        $this->actingAs($this->ownerOf($account))->putJson(route('app.monitoring.rules.escalations', [$rule->environment->project_id, $rule->id]), [
            'version' => 0,
            'escalations' => [
                ['destination_id' => $primary->id, 'delay_minutes' => 30],
                ['destination_id' => $secondary->id, 'delay_minutes' => 5],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('escalations');

        $this->assertDatabaseEmpty('alert_escalations');
    }

    /**
     * Viewers cannot change escalation policy and stale versions are rejected.
     */
    public function test_viewers_cannot_change_escalation_policy_and_stale_versions_are_rejected(): void
    {
        $rule = AlertRule::factory()->ready()->create();
        $account = $rule->environment->project->account;
        $this->onMonitoringTier($account, 'pro');
        $destination = AlertDestination::factory()->for($account)->create();
        $viewer = User::factory()->create();
        $this->addMember($account, $viewer, AccountRole::Viewer);

        $this->actingAs($viewer)->withSession(['account_id' => $account->id])->putJson(route('app.monitoring.rules.escalations', [$rule->environment->project_id, $rule->id]), [
            'version' => 0,
            'escalations' => [['destination_id' => $destination->id, 'delay_minutes' => 5]],
        ])->assertForbidden();

        $this->actingAs($this->ownerOf($account))->putJson(route('app.monitoring.rules.escalations', [$rule->environment->project_id, $rule->id]), [
            'version' => 0,
            'escalations' => [['destination_id' => $destination->id, 'delay_minutes' => 5]],
        ])->assertSuccessful();
        $this->actingAs($this->ownerOf($account))->putJson(route('app.monitoring.rules.escalations', [$rule->environment->project_id, $rule->id]), [
            'version' => 0,
            'escalations' => [],
        ])->assertConflict();
    }

    /**
     * Primary routing cannot reuse an escalation destination.
     */
    public function test_primary_routing_cannot_reuse_an_escalation_destination(): void
    {
        $rule = AlertRule::factory()->ready()->create();
        $account = $rule->environment->project->account;
        $this->onMonitoringTier($account, 'pro');
        $destination = AlertDestination::factory()->for($account)->create();

        $this->actingAs($this->ownerOf($account))->putJson(route('app.monitoring.rules.escalations', [$rule->environment->project_id, $rule->id]), [
            'version' => 0,
            'escalations' => [['destination_id' => $destination->id, 'delay_minutes' => 5]],
        ])->assertSuccessful();
        $this->actingAs($this->ownerOf($account))->putJson(route('app.monitoring.rules.routing', [$rule->environment->project_id, $rule->id]), [
            'version' => 1, 'destinations' => [$destination->id], 'opened' => 1, 'recovered' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('destinations');

        $this->assertDatabaseCount('alert_destination_alert_rule', 0);
        $this->assertDatabaseCount('alert_escalations', 1);
    }

    /**
     * Opening an incident queues escalation after its delay and recovery cancels it.
     */
    public function test_opening_an_incident_queues_escalation_after_its_delay_and_recovery_cancels_it(): void
    {
        $this->travelTo('2026-09-21 12:00:00 UTC');
        [$incident, $escalation] = $this->routedEscalation();

        DB::transaction(function () use ($incident): void {
            app(AlertDispatcher::class)->record($incident, 'opened');
        });

        $delivery = AlertDelivery::query()->where('event', 'escalated')->sole();
        $this->assertSame(AlertDeliveryStatus::Queued, $delivery->status);
        $this->assertTrue($delivery->next_attempt_at?->equalTo(now()->addMinutes(5)));
        $this->assertSame(1, $delivery->payload['escalation']['step']);
        $this->assertSame(5, $delivery->payload['escalation']['delay_minutes']);
        $this->assertSame($escalation->id, $delivery->alert_destination_id);

        $incident->forceFill(['active_slot' => null, 'status' => 'resolved', 'resolved_at' => now(), 'closure_reason' => 'recovered'])->save();
        Http::preventStrayRequests();
        $this->travel(5)->minutes();
        app(AlertDeliveryRunner::class)->process($delivery->id, $delivery->generation);

        $this->assertSame(AlertDeliveryStatus::Cancelled, $this->reload($delivery)->status);
        $this->assertSame('incident_recovered', $this->reload($delivery)->last_error_code);
        Http::assertNothingSent();
    }

    /**
     * Escalation delivery sends when its delay has elapsed.
     */
    public function test_escalation_delivery_sends_when_its_delay_has_elapsed(): void
    {
        $this->travelTo('2026-09-21 12:00:00 UTC');
        [$incident] = $this->routedEscalation();
        DB::transaction(function () use ($incident): void {
            app(AlertDispatcher::class)->record($incident, 'opened');
        });
        $delivery = AlertDelivery::query()->where('event', 'escalated')->sole();
        $this->fakeWebhook(204);

        $this->travel(5)->minutes();
        app(AlertDeliveryRunner::class)->process($delivery->id, $delivery->generation);

        $this->assertSame(AlertDeliveryStatus::Accepted, $this->reload($delivery)->status);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request['event'] === 'escalated');
    }

    /**
     * Repeated open transition does not duplicate escalation delivery.
     */
    public function test_repeated_open_transition_does_not_duplicate_escalation_delivery(): void
    {
        [$incident] = $this->routedEscalation();

        DB::transaction(function () use ($incident): void {
            app(AlertDispatcher::class)->record($incident, 'opened');
            app(AlertDispatcher::class)->record($incident, 'opened');
        });

        $this->assertDatabaseCount('alert_deliveries', 2);
        $this->assertDatabaseCount('alert_escalations', 1);
    }

    /** @return array{Incident, AlertDestination} */
    private function routedEscalation(): array
    {
        $rule = AlertRule::factory()->ready()->create();
        $account = $rule->environment->project->account;
        $this->onMonitoringTier($account, 'pro');
        $primary = AlertDestination::factory()->for($account)->create(['name' => 'Primary']);
        $escalation = AlertDestination::factory()->for($account)->create(['name' => 'Escalation']);
        $rule->destinations()->attach($primary, ['opened' => true, 'recovered' => true]);
        AlertEscalation::factory()->create([
            'alert_rule_id' => $rule->id, 'alert_destination_id' => $escalation->id,
            'delay_minutes' => 5, 'position' => 0, 'enabled' => true,
        ]);

        return [Incident::factory()->for($rule)->create(), $escalation];
    }

    private function fakeWebhook(int $status): void
    {
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->andReturn(['1.1.1.1']);
        Http::preventStrayRequests();
        Http::fake(['https://alerts.example.com/events' => Http::response('', $status)]);
    }
}
