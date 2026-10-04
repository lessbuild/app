<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Data\Monitoring\MonitorObservation;
use App\Enums\AccountRole;
use App\Enums\AlertDeliveryStatus;
use App\Enums\AlertDestinationType;
use App\Enums\AuditAction;
use App\Models\Account;
use App\Models\AlertDelivery;
use App\Models\AlertDestination;
use App\Models\AuditEntry;
use App\Models\Incident;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use App\Models\Project;
use App\Models\User;
use App\Services\Monitoring\MonitorResults;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MonitoringPagesTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->project = Project::factory()->for(Account::factory()->withMember($this->owner))->withServices(['monitoring'])->create();
    }

    /**
     * Monitoring pages need the service to be on.
     */
    public function test_monitoring_pages_need_the_service_to_be_on(): void
    {
        $other = Project::factory()->for($this->project->account)->create();

        $this->actingAs($this->owner)->getJson("/api/app/projects/{$other->id}/monitoring")->assertStatus(409)->assertJsonPath('redirect', "/projects/{$other->id}/services/monitoring");
        $this->actingAs($this->owner)->getJson("/api/app/projects/{$this->project->id}/services/monitoring")->assertJsonRedirect(route('monitoring.monitors', $this->project));
        foreach (['', '/incidents', '/alerts', '/maintenance', '/monitors/create', '/monitors/create?check_type=heartbeat', '/monitors/create?check_type=queue', '/monitors/create?check_type=dns'] as $page) {
            $this->actingAs($this->owner)->getJson("/api/app/projects/{$this->project->id}/monitoring{$page}")->assertOk();
        }
    }

    /**
     * An http monitor is created changed and archived with audit entries.
     */
    public function test_an_http_monitor_is_created_changed_and_archived_with_audit_entries(): void
    {
        $base = "/api/app/projects/{$this->project->id}/monitoring";
        $environment = $this->project->environments()->sole();

        $this->actingAs($this->owner)->postJson("{$base}/monitors", $this->httpMonitor(['environment_id' => $environment->id]))
            ->assertSuccessful()->assertSuccessful();
        $monitor = Monitor::query()->sole();
        $this->assertSame('https://status.example.com/health', $monitor->request_url);
        $this->assertTrue($monitor->enabled);

        $this->actingAs($this->owner)->getJson($base)->assertOk()->assertJsonPath('monitors.0.name', 'Public API')->assertJsonPath('monitors.0.target', 'https://status.example.com/…');
        $this->actingAs($this->owner)->getJson("{$base}/monitors/{$monitor->id}")->assertOk()->assertJsonPath('monitor.id', $monitor->id)->assertDontSee('\/health', false);
        $this->actingAs($this->owner)->getJson("{$base}/monitors/{$monitor->id}/edit")->assertOk()->assertDontSee('\/health', false);

        $this->actingAs($this->owner)->putJson("{$base}/monitors/{$monitor->id}", $this->httpMonitor(['environment_id' => $environment->id, 'name' => 'Renamed', 'request_url' => '', 'version' => 0]))
            ->assertSuccessful()->assertSuccessful();
        $this->assertSame('Renamed', $this->reload($monitor)->name);
        $this->assertSame('https://status.example.com/health', $this->reload($monitor)->request_url, 'A blank URL keeps the stored one.');

        $this->actingAs($this->owner)->putJson("{$base}/monitors/{$monitor->id}", $this->httpMonitor(['environment_id' => $environment->id, 'version' => 0]))->assertStatus(409);

        $this->actingAs($this->owner)->deleteJson("{$base}/monitors/{$monitor->id}", ['version' => 1])->assertJsonRedirect(route('monitoring.monitors', $this->project));
        $this->assertSoftDeleted($monitor);
        $this->actingAs($this->owner)->getJson("{$base}/monitors/{$monitor->id}")->assertOk()->assertJsonPath('monitor.archived', true);

        $this->assertSame(
            [AuditAction::MonitorCreated, AuditAction::MonitorUpdated, AuditAction::MonitorArchived],
            AuditEntry::query()->where('project_id', $this->project->id)->orderBy('id')->pluck('action')->all(),
        );
    }

    /**
     * Monitor input is validated and bound to the project.
     */
    public function test_monitor_input_is_validated_and_bound_to_the_project(): void
    {
        $base = "/api/app/projects/{$this->project->id}/monitoring";
        $foreign = Project::factory()->withServices(['monitoring'])->create();
        $foreignEnvironment = $foreign->environments()->sole();

        $this->actingAs($this->owner)->postJson("{$base}/monitors", $this->httpMonitor(['environment_id' => $foreignEnvironment->id]))->assertJsonValidationErrors('environment_id');
        $this->actingAs($this->owner)->postJson("{$base}/monitors", $this->httpMonitor(['environment_id' => $this->project->environments()->value('id'), 'request_url' => 'http://127.0.0.1/admin']))->assertJsonValidationErrors('request_url');
        $this->assertDatabaseEmpty('monitors');

        $foreignMonitor = Monitor::factory()->create(['environment_id' => $foreignEnvironment->id]);
        $this->actingAs($this->owner)->getJson("{$base}/monitors/{$foreignMonitor->id}")->assertNotFound();
        $this->actingAs($this->owner)->deleteJson("{$base}/monitors/{$foreignMonitor->id}", ['version' => 0])->assertNotFound();
    }

    /**
     * Viewers see monitors but cannot change them.
     */
    public function test_viewers_see_monitors_but_cannot_change_them(): void
    {
        $viewer = User::factory()->create();
        $this->member($viewer, AccountRole::Viewer);
        $monitor = Monitor::factory()->create(['environment_id' => $this->project->environments()->value('id')]);
        $base = "/api/app/projects/{$this->project->id}/monitoring";

        $this->actingAs($viewer)->getJson($base)->assertOk()->assertJsonPath('monitors.0.name', $monitor->name)->assertJsonPath('canManage', false);
        $this->actingAs($viewer)->getJson("{$base}/monitors/create")->assertForbidden();
        $this->actingAs($viewer)->postJson("{$base}/monitors", $this->httpMonitor(['environment_id' => $monitor->environment_id]))->assertForbidden();
        $this->actingAs($viewer)->deleteJson("{$base}/monitors/{$monitor->id}", ['version' => 0])->assertForbidden();
        $this->assertNotSoftDeleted($monitor);
    }

    /**
     * Heartbeat keys are shown once and can be revoked.
     */
    public function test_heartbeat_keys_are_shown_once_and_can_be_revoked(): void
    {
        $monitor = Monitor::factory()->heartbeat()->create(['environment_id' => $this->project->environments()->value('id'), 'heartbeat_token_hash' => null]);
        $base = "/api/app/projects/{$this->project->id}/monitoring/monitors/{$monitor->id}";

        $response = $this->actingAs($this->owner)->postJson("{$base}/key", ['version' => 0])->assertJsonRedirect($base);
        $key = $response->json('secrets.monitor_key');
        $this->assertIsString($key);
        $this->assertStringStartsWith('bch_', $key);
        $this->assertSame(hash('sha256', $key), $this->reload($monitor)->heartbeat_token_hash);
        $this->actingAs($this->owner)->getJson($base)->assertJsonPath('monitor.hasKey', true)->assertJsonPath('monitor.endpoint', route('api.heartbeats.store', ['heartbeat' => $monitor->id]));
        $this->actingAs($this->owner)->getJson($base)->assertDontSee($key);

        $this->actingAs($this->owner)->deleteJson("{$base}/key", ['version' => 1])->assertJsonRedirect($base);
        $this->assertNull($this->reload($monitor)->heartbeat_token_hash);
        $this->assertFalse($this->reload($monitor)->enabled);
    }

    /**
     * Incidents can be acknowledged assigned and noted.
     */
    public function test_incidents_can_be_acknowledged_assigned_and_noted(): void
    {
        $member = User::factory()->create();
        $this->member($member, AccountRole::Member);
        $viewer = User::factory()->create();
        $this->member($viewer, AccountRole::Viewer);
        $incident = $this->incident();
        $url = "/api/app/projects/{$this->project->id}/monitoring/incidents/{$incident->id}";

        $this->actingAs($this->owner)->getJson("/api/app/projects/{$this->project->id}/monitoring/incidents")->assertOk()->assertSee($incident->title);
        $this->actingAs($this->owner)->getJson($url)->assertOk()->assertJsonPath('canRespond', true)->assertJsonFragment(['label' => $member->name])->assertJsonMissing(['label' => $viewer->name]);

        $this->actingAs($this->owner)->patchJson($url, ['action' => 'acknowledge', 'version' => 0])->assertJsonRedirect($url);
        $this->actingAs($this->owner)->patchJson($url, ['action' => 'assign', 'version' => 1, 'assignee_id' => $viewer->id])->assertJsonValidationErrors('assignee_id');
        $this->actingAs($this->owner)->patchJson($url, ['action' => 'assign', 'version' => 1, 'assignee_id' => $member->id])->assertJsonRedirect($url);
        $this->actingAs($this->owner)->patchJson($url, ['action' => 'note', 'version' => 2, 'note' => 'Rolling back the release.'])->assertJsonRedirect($url);
        $this->actingAs($viewer)->patchJson($url, ['action' => 'note', 'version' => 3, 'note' => 'Hi'])->assertForbidden();

        $incident->refresh();
        $this->assertSame('acknowledged', $incident->status);
        $this->assertSame($member->id, $incident->assignee_id);
        $this->assertSame(['acknowledge', 'assign', 'note'], $incident->activities()->orderBy('id')->pluck('action')->all());
        $this->actingAs($this->owner)->getJson($url)->assertSee('Rolling back the release.');

        $this->actingAs($this->owner)->getJson('/api/app/projects/'.Project::factory()->withServices(['monitoring'])->create()->id."/monitoring/incidents/{$incident->id}")->assertNotFound();
    }

    /**
     * People who leave or lose monitoring are unassigned.
     */
    public function test_people_who_leave_or_lose_monitoring_are_unassigned(): void
    {
        $member = User::factory()->create();
        $membership = $this->member($member, AccountRole::Member);
        $incident = $this->incident(['assignee_id' => $member->id]);

        $this->actingAs($this->owner)->putJson(route('app.account.members.services', $membership), ['access' => 'some', 'services' => ['analytics']]);
        $this->assertNull($incident->fresh()?->assignee_id);
        $this->assertSame('assignee_unavailable', $incident->activities()->latest('id')->value('action'));
    }

    /**
     * Admins manage alert destinations and see their deliveries.
     */
    public function test_admins_manage_alert_destinations_and_see_their_deliveries(): void
    {
        $base = "/api/app/projects/{$this->project->id}/monitoring/alerts";

        $secret = $this->actingAs($this->owner)->postJson($base, ['name' => 'Ops webhook', 'type' => 'webhook', 'endpoint_url' => 'https://alerts.example.com/hook', 'enabled' => '1'])
            ->assertSuccessful()->json('secrets.signing_secret');
        $destination = AlertDestination::query()->sole();
        $this->assertSame(AlertDestinationType::Webhook, $destination->type);
        $this->assertSame($destination->signing_secret, $secret);

        $this->actingAs($this->owner)->postJson($base, ['name' => 'Me', 'type' => 'email', 'recipient_user_id' => $this->owner->id, 'enabled' => '1'])->assertSuccessful();
        $this->actingAs($this->owner)->postJson($base, ['name' => 'Bad', 'type' => 'slack', 'endpoint_url' => 'https://example.com/not-slack', 'enabled' => '1'])->assertJsonValidationErrors('endpoint_url');

        $this->actingAs($this->owner)->getJson($base)->assertOk()->assertSee('Ops webhook')->assertSee('alerts.example.com')->assertDontSee('\/hook', false);
        $this->actingAs($this->owner)->getJson("{$base}/{$destination->id}")->assertOk()->assertJsonPath('canManage', true)->assertDontSee((string) $secret);

        DB::table('jobs')->delete();
        $this->actingAs($this->owner)->postJson("{$base}/{$destination->id}/test", ['version' => 0])->assertSuccessful();
        $delivery = AlertDelivery::query()->sole();
        $this->assertSame('test', $delivery->event);
        $this->assertDatabaseCount('jobs', 1);

        $delivery->forceFill(['status' => AlertDeliveryStatus::Failed, 'next_attempt_at' => null])->save();
        $this->actingAs($this->owner)->getJson("{$base}/{$destination->id}")->assertJsonPath('deliveries.0.retryable', true);
        $this->actingAs($this->owner)->postJson("/api/app/projects/{$this->project->id}/monitoring/deliveries/{$delivery->id}/retry", ['generation' => 0, 'confirm' => '1'])->assertSuccessful();
        $this->assertSame(AlertDeliveryStatus::Queued, $delivery->fresh()?->status);

        $this->actingAs($this->owner)->postJson("{$base}/{$destination->id}/rotate", ['version' => 0])->assertSuccessful();
        $this->assertNotSame($secret, $destination->fresh()?->signing_secret);

        $this->actingAs($this->owner)->deleteJson("{$base}/{$destination->id}", ['version' => 1])->assertSuccessful();
        $this->assertSoftDeleted($destination);

        $member = User::factory()->create();
        $this->member($member, AccountRole::Member);
        $this->actingAs($member)->getJson($base)->assertOk()->assertJsonPath('canManage', false)->assertJsonPath('options', null);
        $this->actingAs($member)->postJson($base, ['name' => 'Nope', 'type' => 'email', 'recipient_user_id' => $member->id, 'enabled' => '1'])->assertForbidden();

        $this->assertSame(
            [AuditAction::AlertDestinationCreated, AuditAction::AlertDestinationCreated, AuditAction::AlertDestinationRotated, AuditAction::AlertDestinationArchived],
            AuditEntry::query()->orderBy('id')->pluck('action')->all(),
        );
    }

    /**
     * Maintenance windows are scheduled and suppress new incidents.
     */
    public function test_maintenance_windows_are_scheduled_and_suppress_new_incidents(): void
    {
        $base = "/api/app/projects/{$this->project->id}/monitoring/maintenance";
        $this->travelTo('2026-09-27 10:00:00 UTC');

        $this->actingAs($this->owner)->postJson($base, ['name' => 'Database upgrade', 'starts_at' => '2026-09-27T09:00', 'ends_at' => '2026-09-27T08:30'])->assertJsonValidationErrors('ends_at');
        $this->actingAs($this->owner)->postJson($base, ['name' => 'Database upgrade', 'starts_at' => '2026-09-27T09:00', 'ends_at' => '2026-09-27T11:00'])->assertSuccessful()->assertJsonRedirect($base);
        $window = MaintenanceWindow::query()->sole();
        $this->actingAs($this->owner)->getJson($base)->assertOk()->assertJsonPath('windows.0.name', 'Database upgrade')->assertJsonPath('windows.0.state', 'active');

        $monitor = Monitor::factory()->create(['environment_id' => $this->project->environments()->value('id'), 'trigger_checks' => 1]);
        $this->failCheck($monitor);
        $this->assertDatabaseEmpty('incidents');

        $this->actingAs($this->owner)->putJson("{$base}/{$window->id}", ['name' => 'Database upgrade', 'starts_at' => '2026-09-27T09:00', 'ends_at' => '2026-09-27T09:59'])->assertJsonRedirect($base);
        $this->failCheck($monitor);
        $this->assertSame(1, Incident::query()->count());

        $this->actingAs($this->owner)->deleteJson("{$base}/{$window->id}")->assertJsonRedirect($base);
        $this->assertDatabaseEmpty('maintenance_windows');
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function httpMonitor(array $overrides = []): array
    {
        return [
            'name' => 'Public API', 'check_type' => 'http', 'request_url' => 'https://status.example.com/health',
            'method' => 'GET', 'status_min' => 200, 'status_max' => 299, 'timeout_seconds' => 10, 'interval_minutes' => 5,
            'trigger_checks' => 2, 'recovery_checks' => 2, 'enabled' => '1', 'opened' => '1', 'recovered' => '1',
            ...$overrides,
        ];
    }

    private function member(User $user, AccountRole $role): \App\Models\Membership
    {
        $membership = new \App\Models\Membership;
        $membership->forceFill(['account_id' => $this->project->account_id, 'user_id' => $user->id, 'role' => $role])->save();

        return $membership;
    }

    /** @param array<string, mixed> $attributes */
    private function incident(array $attributes = []): Incident
    {
        $monitor = Monitor::factory()->create(['environment_id' => $this->project->environments()->value('id')]);

        return Incident::factory()->create(['monitor_id' => $monitor->id, ...$attributes]);
    }

    private function failCheck(Monitor $monitor): void
    {
        DB::transaction(fn () => app(MonitorResults::class)->record(
            $monitor->fresh() ?? $monitor, new MonitorObservation('down', 'unexpected_status', httpStatus: 503), CarbonImmutable::now('UTC'), 'Test',
        ));
    }
}
