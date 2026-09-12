<?php

namespace Tests\Feature;

use App\Jobs\DeliverAlertWebhookJob;
use App\Jobs\Server\CollectServerMetricsJob;
use App\Jobs\Web\RefreshWebsiteLogJob;
use App\Models\AlertDestination;
use App\Models\MetricAlertRule;
use App\Models\Provider;
use App\Models\Server;
use App\Models\StatusIncident;
use App\Models\StatusPage;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteHealthCheck;
use App\Models\WebsiteLogSnapshot;
use App\Notifications\AlertEmailNotification;
use App\Notifications\ConfirmStatusSubscriptionNotification;
use App\Notifications\StatusIncidentNotification;
use App\Services\ManagedSsh;
use App\Services\Runner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ObservabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.enforce_entitlements' => false]);
    }

    public function test_admin_can_configure_encrypted_alerts_and_failures_fan_out(): void
    {
        Queue::fake();
        [$owner, , $website] = $this->infrastructure();
        $endpoint = 'https://alerts.example.com/buildpusher';

        $this->actingAs($owner)->post(route('observability.destinations.store'), [
            'name' => 'Engineering', 'type' => 'webhook', 'endpoint' => $endpoint,
            'events' => ['failure', 'recovery'],
        ])->assertRedirect();

        $destination = $owner->currentOrganization->alertDestinations()->sole();
        $this->assertSame($endpoint, $destination->endpoint);
        $this->assertNotSame($endpoint, DB::table('alert_destinations')->value('endpoint'));
        $this->assertArrayNotHasKey('endpoint', $destination->toArray());

        $website->update(['provisioning_status' => Website::STATUS_FAILED, 'provisioning_error' => 'Caddy failed']);
        Queue::assertPushed(DeliverAlertWebhookJob::class, fn (DeliverAlertWebhookJob $job): bool => $job->destinationId === $destination->id
            && $job->payload['event'] === 'failure'
            && $job->payload['category'] === 'website');
    }

    public function test_metric_alert_rule_operations_use_workspace_policy_and_scoped_server_validation(): void
    {
        [$owner, $server] = $this->infrastructure();

        $this->actingAs($owner)->post(route('observability.metric-rules.store'), [
            'name' => 'High CPU',
            'server_id' => $server->id,
            'metric' => 'cpu_percent',
            'operator' => 'gte',
            'threshold' => '85.5',
            'consecutive_breaches' => 3,
            'cooldown_minutes' => 15,
        ])->assertRedirect()->assertSessionHas('success', 'Metric alert created.');

        $rule = MetricAlertRule::query()->sole();
        $this->assertSame($owner->current_organization_id, $rule->organization_id);
        $this->assertSame($owner->id, $rule->created_by);
        $this->assertTrue($rule->is_enabled);
        $this->assertSame(85.5, $rule->threshold);

        $this->actingAs($owner)->delete(route('observability.metric-rules.destroy', $rule))
            ->assertRedirect()->assertSessionHas('success', 'Metric alert deleted.');
        $this->assertDatabaseMissing('metric_alert_rules', ['id' => $rule->id]);
    }

    public function test_metric_alert_rule_denial_precedes_malformed_input_and_does_not_write(): void
    {
        [$owner, $server] = $this->infrastructure();
        $viewer = User::factory()->create();
        $owner->currentOrganization->members()->attach($viewer, ['role' => 'viewer']);
        $viewer->update(['current_organization_id' => $owner->current_organization_id]);

        $this->actingAs($viewer)->post(route('observability.metric-rules.store'), [
            'name' => '',
            'server_id' => $server->id,
            'metric' => 'not-a-metric',
        ])->assertForbidden();

        $this->assertDatabaseCount('metric_alert_rules', 0);
    }

    public function test_metric_alert_rule_server_validation_cannot_cross_workspace(): void
    {
        [$owner, $server] = $this->infrastructure();
        $other = User::factory()->create();
        $otherServer = $other->servers()->create([
            'name' => 'Other', 'public_ip' => '203.0.113.55', 'ssh_private_key' => 'key',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);

        $this->actingAs($owner)->post(route('observability.metric-rules.store'), [
            'name' => 'Foreign server',
            'server_id' => $otherServer->id,
            'metric' => 'cpu_percent',
            'operator' => 'gte',
            'threshold' => 85,
            'consecutive_breaches' => 3,
            'cooldown_minutes' => 15,
        ])->assertSessionHasErrors('server_id');

        $this->assertDatabaseCount('metric_alert_rules', 0);
        $this->assertNotNull($server->fresh());
    }

    public function test_metric_alert_rule_delete_requires_same_workspace_manager(): void
    {
        [$owner] = $this->infrastructure();
        $rule = $owner->currentOrganization->metricAlertRules()->create([
            'created_by' => $owner->id,
            'name' => 'CPU',
            'metric' => 'cpu_percent',
            'operator' => 'gte',
            'threshold' => 80,
            'consecutive_breaches' => 2,
            'cooldown_minutes' => 15,
            'is_enabled' => true,
        ]);
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->delete(route('observability.metric-rules.destroy', $rule))->assertForbidden();
        $this->assertNotNull($rule->fresh());
    }

    public function test_alert_destination_validation_preserves_type_messages_and_queues_a_test_without_exposing_credentials(): void
    {
        Queue::fake();
        [$owner] = $this->infrastructure();

        $this->actingAs($owner)->post(route('observability.destinations.store'), [
            'name' => 'Slack', 'type' => 'slack', 'endpoint' => 'https://example.com/hook',
            'events' => ['failure'],
        ])->assertSessionHasErrors(['endpoint' => 'Slack destinations must use hooks.slack.com.'])
            ->assertSessionHas('_old_input.endpoint', 'https://example.com/hook');
        $this->assertDatabaseCount('alert_destinations', 0);

        $endpoint = 'https://alerts.example.com/buildpusher';
        $this->actingAs($owner)->post(route('observability.destinations.store'), [
            'name' => 'Engineering', 'type' => 'webhook', 'endpoint' => $endpoint,
            'events' => ['failure', 'recovery'],
        ])->assertRedirect();
        $destination = AlertDestination::query()->sole();
        $this->assertNotSame($endpoint, DB::table('alert_destinations')->value('endpoint'));

        $this->actingAs($owner)->post(route('observability.destinations.test', $destination))
            ->assertRedirect()->assertSessionHas('success', 'Test alert queued.');
        Queue::assertPushed(DeliverAlertWebhookJob::class, fn (DeliverAlertWebhookJob $job): bool => $job->destinationId === $destination->id
            && $job->payload['category'] === 'test'
            && ! str_contains(json_encode($job->payload), $endpoint));
    }

    public function test_alert_destination_policy_prevents_foreign_test_and_delete_without_side_effects(): void
    {
        Queue::fake();
        [$owner] = $this->infrastructure();
        $destination = $owner->currentOrganization->alertDestinations()->create([
            'created_by' => $owner->id, 'name' => 'Engineering', 'type' => 'webhook',
            'endpoint' => 'https://alerts.example.com/buildpusher', 'signing_secret' => 'secret',
            'events' => ['failure'], 'is_active' => true,
        ]);
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->post(route('observability.destinations.test', $destination))->assertForbidden();
        $this->actingAs($intruder)->delete(route('observability.destinations.destroy', $destination))->assertForbidden();

        Queue::assertNothingPushed();
        $this->assertNotNull($destination->fresh());
    }

    public function test_public_status_page_exposes_health_without_infrastructure_secrets(): void
    {
        [$owner, , $website] = $this->infrastructure();
        $website->update(['health_check_enabled' => true, 'health_status' => Website::HEALTH_HEALTHY, 'health_last_checked_at' => now()]);
        $website->healthChecks()->create([
            'successful' => true, 'source' => WebsiteHealthCheck::SOURCE_AUTOMATIC,
            'http_status' => 200, 'duration_ms' => 80, 'endpoint' => 'http://app.example.com/', 'checked_at' => now(),
        ]);
        $this->actingAs($owner)->post(route('observability.status-pages.store'), [
            'name' => 'Platform Status', 'slug' => 'platform-status', 'description' => 'Service health',
            'is_published' => '1', 'website_ids' => [$website->id],
        ])->assertRedirect();

        $this->get(route('status.show', 'platform-status'))
            ->assertOk()->assertSee('All systems operational')->assertSee($website->name)
            ->assertDontSee($website->server->public_ip ?? 'never-visible-secret');
        $this->getJson(route('status.report', 'platform-status'))
            ->assertOk()->assertJsonPath('status', 'operational')->assertJsonPath('components.0.uptime_30d', 100);
    }

    public function test_status_page_operations_keep_slug_collision_and_atomic_website_membership_behavior(): void
    {
        [$owner, $server, $website] = $this->infrastructure();
        $attributes = [
            'name' => 'Platform Status', 'slug' => 'platform-status', 'description' => 'Service health',
            'is_published' => '1', 'website_ids' => [$website->id],
        ];

        $this->actingAs($owner)->post(route('observability.status-pages.store'), $attributes)->assertRedirect();
        $first = StatusPage::query()->sole();
        $this->assertSame('platform-status', $first->slug);
        $this->assertSame([$website->id], $first->websites()->pluck('websites.id')->all());

        $this->actingAs($owner)->post(route('observability.status-pages.store'), $attributes)->assertRedirect();
        $second = StatusPage::query()->whereKeyNot($first->id)->sole();
        $this->assertStringStartsWith('platform-status-', $second->slug);

        $this->actingAs($owner)->patch(route('observability.status-pages.update', $first), [
            'name' => 'Updated Status', 'slug' => 'ignored-but-valid', 'description' => null,
            'is_published' => '0', 'website_ids' => [$website->id],
        ])->assertRedirect()->assertSessionHas('success', 'Status page updated.');
        $first->refresh();
        $this->assertSame('Updated Status', $first->name);
        $this->assertSame('platform-status', $first->slug);
        $this->assertFalse($first->is_published);
        $this->assertSame([$website->id], $first->websites()->pluck('websites.id')->all());

        $this->assertNotNull($server->fresh());
    }

    public function test_status_page_policy_denies_foreign_updates_and_deletes_without_mutation(): void
    {
        [$owner, , $website] = $this->infrastructure();
        $page = $owner->currentOrganization->statusPages()->create([
            'created_by' => $owner->id, 'name' => 'Private Status', 'slug' => 'private-status',
            'is_published' => true,
        ]);
        $page->websites()->attach($website);
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->patch(route('observability.status-pages.update', $page), [
            'name' => '', 'is_published' => 'not-bool', 'website_ids' => [],
        ])->assertForbidden();
        $this->actingAs($intruder)->delete(route('observability.status-pages.destroy', $page))->assertForbidden();

        $this->assertSame('Private Status', $page->fresh()->name);
        $this->assertDatabaseHas('status_page_website', ['status_page_id' => $page->id, 'website_id' => $website->id]);
    }

    public function test_status_page_requests_reject_component_websites_from_another_workspace(): void
    {
        [$owner, , $website] = $this->infrastructure();
        $page = $owner->currentOrganization->statusPages()->create([
            'created_by' => $owner->id, 'name' => 'Status', 'slug' => 'status', 'is_published' => true,
        ]);
        $page->websites()->attach($website);
        $other = User::factory()->create();
        $otherServer = $other->servers()->create([
            'name' => 'Other', 'public_ip' => '203.0.113.56', 'ssh_private_key' => 'key',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $otherWebsite = $other->websites()->create([
            'server_id' => $otherServer->id, 'name' => 'Other site', 'description' => 'Other',
            'environment' => '', 'url' => 'other.example.com', 'provisioning_status' => Website::STATUS_ACTIVE,
        ]);

        $this->actingAs($owner)->patch(route('observability.status-pages.update', $page), [
            'name' => 'Status', 'is_published' => '1', 'website_ids' => [$otherWebsite->id],
        ])->assertSessionHasErrors('website_ids.0');

        $this->assertDatabaseHas('status_page_website', ['status_page_id' => $page->id, 'website_id' => $website->id]);
        $this->assertDatabaseMissing('status_page_website', ['status_page_id' => $page->id, 'website_id' => $otherWebsite->id]);
    }

    public function test_status_incident_operations_preserve_kind_validation_resolution_and_notification_order(): void
    {
        Notification::fake();
        [$owner, , $website] = $this->infrastructure();
        $page = $owner->currentOrganization->statusPages()->create([
            'created_by' => $owner->id, 'name' => 'Status', 'slug' => 'incident-status', 'is_published' => true,
        ]);
        $page->websites()->attach($website);
        $startsAt = now()->format('Y-m-d H:i:s');

        $this->actingAs($owner)->post(route('observability.incidents.store'), [
            'status_page_id' => $page->id, 'kind' => 'incident', 'status' => 'investigating',
            'severity' => 'major', 'title' => 'Latency', 'message' => 'Investigating latency.',
            'starts_at' => $startsAt,
        ])->assertRedirect()->assertSessionHas('success', 'Status update published.');
        $incident = StatusIncident::query()->sole();
        $this->assertNull($incident->resolved_at);
        Notification::assertNothingSent();

        $this->actingAs($owner)->post(route('observability.incidents.store'), [
            'status_page_id' => $page->id, 'kind' => 'incident', 'status' => 'completed',
            'severity' => 'major', 'title' => 'Invalid', 'message' => 'Wrong status.',
            'starts_at' => $startsAt,
        ])->assertSessionHasErrors(['status' => 'Choose a status that matches the update type.']);
        $this->assertDatabaseCount('status_incidents', 1);

        $this->actingAs($owner)->patch(route('observability.incidents.update', $incident), [
            'kind' => 'incident', 'status' => 'resolved', 'severity' => 'major', 'title' => 'Resolved',
            'message' => 'The incident is resolved.', 'starts_at' => $startsAt,
        ])->assertRedirect()->assertSessionHas('success', 'Status update saved and subscribers notified.');
        $resolvedAt = $incident->fresh()->resolved_at;
        $this->assertNotNull($resolvedAt);

        $this->actingAs($owner)->patch(route('observability.incidents.update', $incident), [
            'kind' => 'incident', 'status' => 'monitoring', 'severity' => 'major', 'title' => 'Monitoring',
            'message' => 'Monitoring recovery.', 'starts_at' => $startsAt,
        ])->assertRedirect();
        $this->assertNull($incident->fresh()->resolved_at);
    }

    public function test_status_incident_policy_denies_malformed_creation_before_any_write(): void
    {
        [$owner, , $website] = $this->infrastructure();
        $page = $owner->currentOrganization->statusPages()->create([
            'created_by' => $owner->id, 'name' => 'Status', 'slug' => 'denied-status', 'is_published' => true,
        ]);
        $page->websites()->attach($website);
        $viewer = User::factory()->create();
        $owner->currentOrganization->members()->attach($viewer, ['role' => 'viewer']);
        $viewer->update(['current_organization_id' => $owner->current_organization_id]);

        $this->actingAs($viewer)->post(route('observability.incidents.store'), [
            'status_page_id' => $page->id, 'kind' => 'unknown', 'status' => 'invalid',
        ])->assertForbidden();

        $this->assertDatabaseCount('status_incidents', 0);
    }

    public function test_server_metrics_and_runtime_logs_are_collected_and_encrypted(): void
    {
        [, $server, $website] = $this->infrastructure();
        $metricsRunner = $this->runner("load_1m=0.25\nload_5m=0.5\nload_15m=0.75\nmemory_percent=41\ndisk_percent=62\nuptime_seconds=90061\n");
        (new CollectServerMetricsJob($server->id))->handle($metricsRunner);
        $metric = $server->metrics()->sole();
        $this->assertSame(41, $metric->memory_percent);
        $this->assertSame(62, $metric->disk_percent);
        $this->assertSame(90061, $metric->uptime_seconds);

        $snapshot = $website->runtimeLogs()->create(['type' => 'application', 'status' => WebsiteLogSnapshot::STATUS_QUEUED]);
        $runtimeRunner = $this->runner("[2026-09-05] production.INFO: healthy\n");
        (new RefreshWebsiteLogJob($website->id, 'application'))->handle($runtimeRunner);
        $snapshot->refresh();
        $this->assertSame(WebsiteLogSnapshot::STATUS_READY, $snapshot->status);
        $this->assertStringContainsString('production.INFO', $snapshot->log);
        $this->assertNotSame($snapshot->log, DB::table('website_log_snapshots')->where('id', $snapshot->id)->value('log'));
    }

    public function test_runtime_log_report_and_retention_are_workspace_scoped(): void
    {
        [$owner, , $website] = $this->infrastructure();
        $intruder = User::factory()->create();
        $website->runtimeLogs()->create([
            'type' => 'application',
            'status' => WebsiteLogSnapshot::STATUS_READY,
            'log' => "production.INFO: healthy\nproduction.ERROR: failed",
            'refreshed_at' => now(),
        ]);

        $this->actingAs($owner)->getJson(route('websites.runtime-logs.show', [$website, 'application']))
            ->assertOk()
            ->assertJsonPath('status', WebsiteLogSnapshot::STATUS_READY)
            ->assertJsonPath('log', "production.INFO: healthy\nproduction.ERROR: failed")
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($intruder)->getJson(route('websites.runtime-logs.show', [$website, 'application']))
            ->assertForbidden();

        $this->actingAs($owner)->patch(route('websites.runtime-logs.retention', $website), [
            'log_retention_lines' => 5000,
        ])->assertRedirect();
        $this->assertSame(5000, $website->fresh()->log_retention_lines);
        $this->actingAs($owner)->patch(route('websites.runtime-logs.retention', $website), [
            'log_retention_lines' => 999999,
        ])->assertSessionHasErrors('log_retention_lines');
    }

    public function test_active_website_can_queue_a_runtime_log_refresh_but_inactive_website_cannot(): void
    {
        Queue::fake();
        [$owner, , $website] = $this->infrastructure();

        $this->actingAs($owner)->post(route('websites.runtime-logs.refresh', [$website, 'application']))
            ->assertRedirect()
            ->assertSessionHas('success', 'Runtime log refresh queued.');
        $this->assertDatabaseHas('website_log_snapshots', [
            'website_id' => $website->id,
            'type' => 'application',
            'status' => WebsiteLogSnapshot::STATUS_QUEUED,
        ]);
        Queue::assertPushed(RefreshWebsiteLogJob::class, fn (RefreshWebsiteLogJob $job): bool => $job->websiteId === $website->id
            && $job->type === 'application');

        $website->update(['provisioning_status' => Website::STATUS_FAILED]);
        $this->actingAs($owner)->post(route('websites.runtime-logs.refresh', [$website, 'access']))
            ->assertRedirect()
            ->assertSessionHas('info', 'Runtime logs are available after website provisioning completes.');
        Queue::assertPushedTimes(RefreshWebsiteLogJob::class, 1);
        $this->assertDatabaseMissing('website_log_snapshots', ['type' => 'access']);
    }

    public function test_email_discord_teams_and_pagerduty_alert_payloads_are_supported(): void
    {
        Notification::fake();
        Http::preventStrayRequests();
        Http::fake(['https://8.8.8.8/*' => Http::response([], 202), 'https://events.pagerduty.com/*' => Http::response(['status' => 'success'], 202)]);
        [$owner] = $this->infrastructure();
        $payload = [
            'event' => 'failure', 'category' => 'deployment', 'resource_id' => 91,
            'title' => 'Deployment failed', 'message' => 'Health verification failed.',
        ];
        foreach ([
            ['email', 'ops@example.com'],
            ['discord', 'https://8.8.8.8/discord'],
            ['teams', 'https://8.8.8.8/teams'],
            ['pagerduty', 'routing_key_12345678901234567890'],
        ] as [$type, $endpoint]) {
            $destination = $owner->currentOrganization->alertDestinations()->create([
                'created_by' => $owner->id,
                'name' => ucfirst($type),
                'type' => $type,
                'endpoint' => $endpoint,
                'signing_secret' => 'secret',
                'events' => ['failure', 'recovery'],
                'is_active' => true,
            ]);
            (new DeliverAlertWebhookJob($destination->id, $payload))->handle();
            $this->assertNotNull($destination->fresh()->last_delivered_at);
        }

        Notification::assertSentOnDemand(AlertEmailNotification::class);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://8.8.8.8/discord'
            && $request['content'] === "**Deployment failed**\nHealth verification failed.");
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://8.8.8.8/teams'
            && $request['type'] === 'message'
            && $request['attachments'][0]['content']['type'] === 'AdaptiveCard');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://events.pagerduty.com/v2/enqueue'
            && $request['routing_key'] === 'routing_key_12345678901234567890'
            && $request['event_action'] === 'trigger'
            && $request['dedup_key'] === 'deployment-91');
    }

    public function test_status_subscriptions_require_confirmation_and_receive_incident_updates(): void
    {
        Notification::fake();
        [$owner, , $website] = $this->infrastructure();
        $page = $owner->currentOrganization->statusPages()->create([
            'created_by' => $owner->id,
            'name' => 'Application Status',
            'slug' => 'application-status',
            'is_published' => true,
        ]);
        $page->websites()->attach($website);

        $this->post(route('status.subscriptions.store', $page->slug), ['email' => 'Ops@Example.com'])
            ->assertRedirect()
            ->assertSessionHas('status_subscription');
        $subscription = $page->subscriptions()->sole();
        $this->assertSame('ops@example.com', $subscription->email);
        $this->assertNotSame('ops@example.com', DB::table('status_subscriptions')->value('email'));
        $this->assertNull($subscription->verified_at);
        Notification::assertSentOnDemand(ConfirmStatusSubscriptionNotification::class);

        $confirmationToken = 'known-confirmation-token';
        $unsubscribeToken = 'known-unsubscribe-token';
        $subscription->update([
            'verification_token_hash' => hash('sha256', $confirmationToken),
            'unsubscribe_token' => $unsubscribeToken,
        ]);
        $this->get(route('status.subscriptions.confirm', [$subscription, 'wrong']))->assertNotFound();
        $this->get(route('status.subscriptions.confirm', [$subscription, $confirmationToken]))
            ->assertRedirect(route('status.show', $page->slug));
        $this->assertNotNull($subscription->fresh()->verified_at);

        $this->actingAs($owner)->post(route('observability.incidents.store'), [
            'status_page_id' => $page->id,
            'kind' => 'incident',
            'status' => 'investigating',
            'severity' => 'major',
            'title' => 'API latency',
            'message' => 'Requests are slower than expected.',
            'starts_at' => now()->format('Y-m-d H:i:s'),
        ])->assertRedirect();
        Notification::assertSentOnDemand(StatusIncidentNotification::class);
        $this->get(route('status.show', $page->slug))->assertOk()->assertSee('API latency');
        $this->getJson(route('status.report', $page->slug))
            ->assertOk()->assertJsonPath('incidents.0.status', 'investigating');

        $this->get(route('status.subscriptions.unsubscribe', [$subscription, $unsubscribeToken]))
            ->assertRedirect(route('status.show', $page->slug));
        $this->assertDatabaseMissing('status_subscriptions', ['id' => $subscription->id]);
    }

    /** @return array{User, Server, Website} */
    private function infrastructure(): array
    {
        $owner = User::factory()->create();
        $provider = $owner->providers()->create([
            'name' => 'DigitalOcean', 'provider' => Provider::TYPE_DIGITALOCEAN,
            'token' => 'token', 'description' => 'Cloud provider',
        ]);
        $server = $owner->servers()->create([
            'provider_id' => $provider->id, 'name' => 'Production', 'public_ip' => '203.0.113.10',
            'ssh_private_key' => 'private-key', 'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $website = $owner->websites()->create([
            'server_id' => $server->id, 'name' => 'Application', 'description' => 'Website',
            'environment' => 'APP_KEY=secret', 'url' => 'app.example.com', 'provisioning_status' => Website::STATUS_ACTIVE,
        ]);

        return [$owner, $server, $website];
    }

    private function runner(string $output): Runner
    {
        $process = Mockery::mock(Process::class);
        $process->shouldReceive('isSuccessful')->once()->andReturnTrue();
        $process->shouldReceive('getOutput')->zeroOrMoreTimes()->andReturn($output);
        $process->shouldReceive('getErrorOutput')->zeroOrMoreTimes()->andReturn('');
        $ssh = Mockery::mock(ManagedSsh::class);
        $ssh->shouldReceive('execute')->once()->andReturn($process);
        $runner = Mockery::mock(Runner::class);
        $runner->shouldReceive('server')->once()->andReturnSelf();
        $runner->shouldReceive('create')->once()->andReturn($ssh);

        return $runner;
    }
}
