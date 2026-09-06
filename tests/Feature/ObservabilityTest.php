<?php

namespace Tests\Feature;

use App\Jobs\DeliverAlertWebhookJob;
use App\Jobs\Server\CollectServerMetricsJob;
use App\Jobs\Web\RefreshWebsiteLogJob;
use App\Models\Provider;
use App\Models\Server;
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
