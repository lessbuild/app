<?php

namespace Tests\Feature;

use App\Models\OperationalIncident;
use App\Models\Provider;
use App\Models\User;
use App\Services\IncidentNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
}
