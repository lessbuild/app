<?php

namespace Tests\Feature;

use App\Jobs\DeliverAlertWebhookJob;
use App\Models\OperationalIncident;
use App\Models\Provider;
use App\Models\User;
use App\Models\Website;
use App\Services\IncidentNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OperationalIncidentTest extends TestCase
{
    use RefreshDatabase;

    public function test_failures_are_deduplicated_encrypted_and_recovery_closes_the_incident_even_when_inbox_is_disabled(): void
    {
        [$owner, $server] = $this->server();
        $owner->currentOrganization->update(['notification_preferences' => ['categories' => [], 'recoveries' => false]]);
        $notifier = app(IncidentNotifier::class);

        $notifier->fail($owner, 'server', $server->id, 'Server failed', 'Sensitive first failure');
        $notifier->fail($owner, 'server', $server->id, 'Server still failed', 'Sensitive repeated failure');

        $incident = OperationalIncident::query()->sole();
        $this->assertSame(2, $incident->occurrences);
        $this->assertCount(2, $incident->events);
        $this->assertNotSame('Sensitive repeated failure', DB::table('operational_incidents')->value('summary'));
        $this->assertStringNotContainsString('Sensitive first failure', DB::table('operational_incident_events')->first()->message);
        $this->assertSame(0, $owner->notifications()->count());

        $notifier->recoverIfOpen($owner, 'server', $server->id, 'Server recovered', 'Provisioning completed.');
        $this->assertSame(OperationalIncident::STATUS_RESOLVED, $incident->fresh()->status);
        $this->assertNull($incident->fresh()->active_key);
        $this->assertSame('Provisioning completed.', $incident->fresh()->resolution);
    }

    public function test_repeated_external_alerts_carry_one_incident_identity_and_occurrence_count(): void
    {
        Queue::fake();
        [$owner, $server] = $this->server();
        $destination = $owner->currentOrganization->alertDestinations()->create([
            'created_by' => $owner->id,
            'name' => 'Operations',
            'type' => 'webhook',
            'endpoint' => 'https://8.8.8.8/buildpusher',
            'signing_secret' => 'secret',
            'events' => ['failure'],
            'is_active' => true,
        ]);
        $notifier = app(IncidentNotifier::class);

        $notifier->fail($owner, 'server', $server->id, 'Server failed', 'First failure.');
        $notifier->fail($owner, 'server', $server->id, 'Server still failed', 'Repeated failure.');

        $alerts = Queue::pushed(DeliverAlertWebhookJob::class)
            ->filter(fn (DeliverAlertWebhookJob $job): bool => $job->destinationId === $destination->id)
            ->values();

        $this->assertCount(2, $alerts);
        $first = $alerts[0]->payload;
        $second = $alerts[1]->payload;
        $this->assertSame($first['incident_id'], $second['incident_id']);
        $this->assertSame("server-{$server->id}", $first['dedup_key']);
        $this->assertSame($first['dedup_key'], $second['dedup_key']);
        $this->assertSame(1, $first['incident_occurrences']);
        $this->assertSame(2, $second['incident_occurrences']);

        Http::fake(['https://8.8.8.8/*' => Http::response([], 202)]);
        (new DeliverAlertWebhookJob($destination->id, $second))->handle();
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://8.8.8.8/buildpusher'
            && $request['incident_id'] === $second['incident_id']
            && $request['incident_occurrences'] === 2
            && $request['dedup_key'] === $second['dedup_key']);
    }

    public function test_responders_can_acknowledge_assign_note_and_resolve_while_other_workspaces_are_denied(): void
    {
        [$owner, $server] = $this->server();
        $operator = User::factory()->create();
        $owner->currentOrganization->members()->attach($operator, ['role' => 'operator']);
        $operator->update(['current_organization_id' => $owner->current_organization_id]);
        app(IncidentNotifier::class)->fail($owner, 'server', $server->id, 'Server failed', 'Connection refused');
        $incident = OperationalIncident::query()->sole();

        $this->actingAs($operator)->post(route('observability.operational-incidents.acknowledge', $incident))->assertRedirect();
        $this->actingAs($operator)->patch(route('observability.operational-incidents.assign', $incident), ['assigned_to' => $owner->id])->assertRedirect();
        $this->actingAs($operator)->post(route('observability.operational-incidents.notes.store', $incident), ['message' => 'Restart attempted.'])->assertRedirect();
        $this->actingAs($operator)->post(route('observability.operational-incidents.resolve', $incident), ['resolution' => 'Service restarted and verified.'])->assertRedirect();

        $incident->refresh();
        $this->assertSame(OperationalIncident::STATUS_RESOLVED, $incident->status);
        $this->assertSame($owner->id, $incident->assigned_to);
        $this->assertEqualsCanonicalizing(['detected', 'acknowledged', 'assigned', 'note', 'resolved'], $incident->events()->pluck('type')->all());

        $intruder = User::factory()->create();
        $this->actingAs($intruder)->post(route('observability.operational-incidents.notes.store', $incident), ['message' => 'No access'])->assertForbidden();
    }

    public function test_investigation_note_composer_is_a_dialog_and_reopens_for_validation_errors(): void
    {
        [$owner, $server] = $this->server();
        $operator = User::factory()->create();
        $owner->currentOrganization->members()->attach($operator, ['role' => 'operator']);
        $operator->update(['current_organization_id' => $owner->current_organization_id]);
        app(IncidentNotifier::class)->fail($owner, 'server', $server->id, 'Server failed', 'Connection refused');
        $incident = OperationalIncident::query()->sole();
        $dialogId = 'operational-incident-note-'.$incident->id;
        $dialogKey = 'incident-note-'.$incident->id;
        $dialogUrl = route('observability.index', ['dialog' => $dialogKey]);
        $dialogPattern = '/<dialog(?=[^>]*id="'.preg_quote($dialogId, '/').'")(?=[^>]*\sopen(?:\s|>))[^>]*>/';

        $default = $this->actingAs($operator)->get(route('observability.index'))
            ->assertSuccessful()
            ->assertSee('data-modal-trigger="'.$dialogId.'"', false);
        $this->assertDoesNotMatchRegularExpression($dialogPattern, $default->getContent());
        $this->assertMatchesRegularExpression($dialogPattern, $this->actingAs($operator)->get($dialogUrl)->assertSuccessful()->getContent());

        $errorPage = $this->actingAs($operator)->from($dialogUrl)->followingRedirects()->post(
            route('observability.operational-incidents.notes.store', $incident),
            [
                '_operational_incident_form' => 'note',
                '_operational_incident_id' => $incident->id,
                'message' => str_repeat('x', 5001),
            ],
        )->assertSuccessful();
        $this->assertMatchesRegularExpression($dialogPattern, $errorPage->getContent());
        $errorPage->assertSee('must not be greater than 5000 characters.');
        $this->assertSame(1, $incident->events()->count());
    }

    public function test_incident_timeline_is_lazy_loaded_while_response_actions_remain_explicit(): void
    {
        [$owner, $server] = $this->server();
        app(IncidentNotifier::class)->fail($owner, 'server', $server->id, 'Server failed', '<script>alert("incident")</script>');
        $incident = OperationalIncident::query()->sole();

        $this->actingAs($owner)
            ->get(route('observability.index'))
            ->assertSuccessful()
            ->assertSee('Timeline and response')
            ->assertSee('Response actions')
            ->assertSee('data-modal-trigger="operational-incident-timeline-dialog"', false)
            ->assertDontSee('incident")</script>', false)
            ->assertDontSee('Connection refused');

        $this->get(route('observability.index', [
            'dialog' => 'operational-incident-'.$incident->id,
        ]))
            ->assertSuccessful()
            ->assertViewHas('selectedOperationalIncident', fn ($selected): bool => $selected?->is($incident) ?? false)
            ->assertSee('data-modal-initial-open="true"', false)
            ->assertDontSee('incident")</script>', false);

        $this->get(route('observability.index', [
            'fragment' => 'operational-incident',
            'incident_id' => $incident->id,
        ]))
            ->assertSuccessful()
            ->assertViewIs('components.scenes.observability.operational-incident-content')
            ->assertSee('data-operational-incident-content', false)
            ->assertSee('&lt;script&gt;alert(&quot;incident&quot;)&lt;/script&gt;', false)
            ->assertDontSee('<script>', false)
            ->assertSee('Timeline');

        $intruder = User::factory()->create();
        $this->actingAs($intruder)->get(route('observability.index', [
            'fragment' => 'operational-incident',
            'incident_id' => $incident->id,
        ]))->assertNotFound();
    }

    public function test_observability_keeps_active_response_visible_and_collapses_resolved_history(): void
    {
        [$owner, $server] = $this->server();
        $website = $owner->websites()->create([
            'server_id' => $server->id,
            'name' => 'Application',
            'description' => 'Website',
            'environment' => 'APP_KEY=secret',
            'url' => 'app.example.com',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
        $notifier = app(IncidentNotifier::class);

        $notifier->fail($owner, 'server', $server->id, 'Server failed', 'Server response details.');
        $notifier->recoverIfOpen($owner, 'server', $server->id, 'Server recovered', 'Server recovery details.');
        $notifier->fail($owner, 'website', $website->id, 'Website failed', 'Website response details.');

        $content = $this->actingAs($owner)
            ->get(route('observability.index'))
            ->assertSuccessful()
            ->getContent();

        $this->assertStringContainsString('1 active', $content);
        $this->assertStringContainsString('1 resolved', $content);
        $this->assertMatchesRegularExpression('/<details id="operational-incident-history"[^>]*>/', $content);
        $this->assertDoesNotMatchRegularExpression('/<details id="operational-incident-history"[^>]*\bopen\b[^>]*>/', $content);
        $this->assertStringContainsString('Acknowledge', $content);
        $this->assertStringContainsString('Timeline and response', $content);
        $this->assertStringContainsString(route('observability.operational-incidents.resolve', $this->activeIncident()), $content);
    }

    public function test_authorization_precedes_validation_and_business_rejections_do_not_write(): void
    {
        [$owner, $server] = $this->server();
        app(IncidentNotifier::class)->fail($owner, 'server', $server->id, 'Server failed', 'Connection refused');
        $incident = OperationalIncident::query()->sole();
        $intruder = User::factory()->create();

        $this->actingAs($intruder)
            ->patch(route('observability.operational-incidents.assign', $incident), ['assigned_to' => 'not-an-integer'])
            ->assertForbidden();
        $this->actingAs($intruder)
            ->post(route('observability.operational-incidents.notes.store', $incident), ['message' => str_repeat('x', 5001)])
            ->assertForbidden();
        $this->assertSame(1, $incident->events()->count());

        $operator = User::factory()->create();
        $owner->currentOrganization->members()->attach($operator, ['role' => 'operator']);
        $operator->update(['current_organization_id' => $owner->current_organization_id]);
        $nonResponder = User::factory()->create();

        $assignmentRejection = $this->actingAs($operator)
            ->patch(route('observability.operational-incidents.assign', $incident), ['assigned_to' => $nonResponder->id]);
        $assignmentRejection->assertStatus(422);
        $this->assertSame(
            'The selected responder is not a member of this workspace.',
            $assignmentRejection->baseResponse->exception?->getMessage(),
        );
        $this->assertNull($incident->fresh()->assigned_to);
        $this->assertSame(1, $incident->events()->count());

        $incident->update([
            'status' => OperationalIncident::STATUS_RESOLVED,
            'active_key' => null,
            'resolved_at' => now(),
        ]);
        $acknowledgementRejection = $this->actingAs($operator)
            ->post(route('observability.operational-incidents.acknowledge', $incident));
        $acknowledgementRejection->assertStatus(422);
        $this->assertSame(
            'A resolved incident cannot be acknowledged.',
            $acknowledgementRejection->baseResponse->exception?->getMessage(),
        );
        $this->assertSame(OperationalIncident::STATUS_RESOLVED, $incident->fresh()->status);
        $this->assertSame(1, $incident->events()->count());

        $viewer = User::factory()->create();
        $owner->currentOrganization->members()->attach($viewer, ['role' => 'viewer']);
        $viewer->update(['current_organization_id' => $owner->current_organization_id]);
        $this->actingAs($viewer)->get(route('observability.operational-incidents.export'))->assertForbidden();
    }

    public function test_auditor_can_export_workspace_evidence_and_pruning_only_removes_expired_resolved_incidents(): void
    {
        [$owner, $server] = $this->server();
        app(IncidentNotifier::class)->fail($owner, 'server', $server->id, '=Failure', '@Sensitive details');
        $incident = OperationalIncident::query()->sole();
        $incident->update(['status' => 'resolved', 'active_key' => null, 'resolution' => '+Fixed', 'resolved_at' => now()->subDays(400)]);
        $auditor = User::factory()->create();
        $owner->currentOrganization->members()->attach($auditor, ['role' => 'auditor']);
        $auditor->update(['current_organization_id' => $owner->current_organization_id]);

        $response = $this->actingAs($auditor)->get(route('observability.operational-incidents.export'));
        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();
        $this->assertStringContainsString("'=Failure", $csv);
        $this->assertStringContainsString("'@Sensitive details", $csv);
        $this->assertStringContainsString("'+Fixed", $csv);

        $this->artisan('buildpusher:operational-incidents:prune', ['--days' => 365])->assertSuccessful();
        $this->assertDatabaseMissing('operational_incidents', ['id' => $incident->id]);
    }

    private function server(): array
    {
        $owner = User::factory()->create();
        $provider = $owner->providers()->create(['name' => 'Cloud', 'description' => 'Cloud provider', 'provider' => Provider::TYPE_DIGITALOCEAN, 'token' => 'token']);
        $server = $owner->servers()->create(['provider_id' => $provider->id, 'name' => 'Production', 'public_ip' => '203.0.113.10', 'ssh_private_key' => 'key']);

        return [$owner, $server];
    }

    private function activeIncident(): OperationalIncident
    {
        return OperationalIncident::query()->where('status', OperationalIncident::STATUS_OPEN)->sole();
    }
}
