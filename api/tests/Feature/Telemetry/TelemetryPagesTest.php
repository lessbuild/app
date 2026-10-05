<?php

declare(strict_types=1);

namespace Tests\Feature\Telemetry;

use App\Enums\AccountRole;
use App\Enums\AuditAction;
use App\Enums\IssueStatus;
use App\Models\Account;
use App\Models\AuditEntry;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\IngestReceipt;
use App\Models\IngestToken;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Release;
use App\Models\TelemetryEvent;
use App\Models\User;
use App\Services\Deploy\DeploymentMarkers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class TelemetryPagesTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    private User $owner;

    private Project $project;

    private Environment $production;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->project = Project::factory()->for(Account::factory()->withMember($this->owner))->withServices(['monitoring'])->create();
        $this->production = $this->project->environments()->where('slug', 'production')->firstOrFail();
    }

    /**
     * Saved views keep monitoring filters for the project.
     */
    public function test_saved_views_keep_monitoring_filters_for_the_project(): void
    {
        $this->owner->forceFill(['current_account_id' => $this->project->account_id])->save();
        $base = "/api/app/projects/{$this->project->id}/monitoring";
        $this->actingAs($this->owner)->getJson("{$base}/events?type=exception&range=24h")->assertOk()->assertJsonPath('filters.type', 'exception');

        $this->actingAs($this->owner)->postJson('/api/app/saved-views', ['saved_view_page' => 'monitoring.events', 'saved_view_name' => 'Exceptions today', 'parameters' => ['project' => $this->project->id], 'query' => ['type' => 'exception', 'range' => '24h']])
            ->assertCreated()->assertJsonPath('url', route('monitoring.events', ['project' => $this->project->id, 'range' => '24h', 'type' => 'exception']));
        $this->actingAs($this->owner)->getJson("/api/app/saved-views?page=monitoring.events&project={$this->project->id}")->assertJsonPath('views.0.name', 'Exceptions today');
        $this->actingAs($this->owner)->getJson("/api/app/saved-views?page=monitoring.issues&project={$this->project->id}")->assertJsonCount(0, 'views');

        // Another project's page doesn't list it.
        $other = Project::factory()->for(Account::query()->findOrFail($this->project->account_id))->withServices(['monitoring'])->create();
        $this->actingAs($this->owner)->getJson("/api/app/saved-views?page=monitoring.events&project={$other->id}")->assertJsonCount(0, 'views');
    }

    /**
     * Ingested events show up as issues events traces and releases.
     */
    public function test_ingested_events_show_up_as_issues_events_traces_and_releases(): void
    {
        IngestToken::factory()->for($this->production)->withSecret('pages-key')->create();
        $this->withToken('pages-key')->postJson(route('api.ingest'), ['batch_id' => 'pages', 'events' => [
            ['id' => 'request', 'type' => 'request', 'name' => 'GET /checkout', 'route' => '/checkout', 'service' => 'shop', 'status_code' => 200,
                'duration_ms' => 42, 'trace_id' => 'trace-pages', 'span_id' => 'span-a', 'attributes' => ['service.version' => '2.4.0']],
            ['id' => 'boom', 'type' => 'exception', 'severity' => 'error', 'name' => 'PaymentDeclined', 'title' => 'Payment declined',
                'route' => '/checkout', 'service' => 'shop', 'trace_id' => 'trace-pages', 'span_id' => 'span-a', 'attributes' => ['service.version' => '2.4.0']],
        ]])->assertOk();
        $this->flushHeaders();
        $base = "/api/app/projects/{$this->project->id}/monitoring";
        $issue = Issue::query()->sole();

        $this->actingAs($this->owner)->getJson("{$base}/issues")->assertOk()->assertSee('Payment declined')
            ->assertJsonPath('stats.open', 1)->assertJsonPath('stats.newToday', 1)->assertJsonPath('stats.events', 1)->assertJsonPath('stats.hourly.23', 1)
            ->assertJsonPath('issues.0.trend.11', 1);
        $this->actingAs($this->owner)->getJson("{$base}/issues/{$issue->id}")->assertOk()->assertSee('Payment declined')->assertJsonPath('issue.open', true)->assertJsonPath('canUpdate', true)
            ->assertJsonPath('issue.where.0', ['label' => '/checkout', 'value' => 100])->assertJsonPath('issue.firstRelease', '2.4.0')->assertJsonPath('issue.latest.traceId', 'trace-pages');
        $this->actingAs($this->owner)->getJson("{$base}/events")->assertOk()->assertJsonFragment(['name' => 'GET /checkout']);
        $this->actingAs($this->owner)->getJson("{$base}/events?type=exception")->assertOk()->assertJsonFragment(['name' => 'PaymentDeclined'])->assertJsonMissing(['name' => 'GET /checkout']);
        $this->actingAs($this->owner)->getJson("{$base}/traces/trace-pages")->assertOk()->assertJsonFragment(['name' => 'GET /checkout']);
        $this->actingAs($this->owner)->getJson("{$base}/traces/unknown-trace")->assertNotFound();
        $this->actingAs($this->owner)->getJson("{$base}/dependencies")->assertOk();
        $this->actingAs($this->owner)->getJson("{$base}/releases")->assertOk()->assertSee('2.4.0')->assertJsonPath('releases.0.issues', 1)->assertJsonPath('releases.0.errorRate', 0)->assertJsonPath('releases.0.averageMs', 42);
        $release = $this->project->releases()->sole();
        $this->actingAs($this->owner)->getJson("{$base}/releases/{$release->id}")->assertOk()->assertSee('Payment declined');
        $event = $issue->telemetryEvents()->firstOrFail();
        $this->actingAs($this->owner)->getJson("{$base}/events/{$event->id}")->assertOk()->assertSee('PaymentDeclined');

        $foreign = Project::factory()->withServices(['monitoring'])->create();
        $this->actingAs($this->owner)->getJson("/api/app/projects/{$foreign->id}/monitoring/issues/{$issue->id}")->assertNotFound();
        $this->actingAs($this->owner)->getJson("/api/app/projects/{$this->project->id}/monitoring/issues/".Issue::factory()->create()->id)->assertNotFound();
    }

    /**
     * A trace links to the deploy that served it.
     */
    public function test_a_trace_links_to_the_deploy_that_served_it(): void
    {
        $base = "/api/app/projects/{$this->project->id}/monitoring";
        TelemetryEvent::factory()->create(['environment_id' => $this->production->id, 'trace_id' => 'early-trace', 'occurred_at' => now()->subDays(2)]);
        $this->actingAs($this->owner)->getJson("{$base}/traces/early-trace")->assertOk()->assertJsonPath('deployment', null);

        Deployment::factory()->create(['environment_id' => $this->production->id, 'release_id' => Release::factory()->create(['project_id' => $this->project->id, 'version' => 'old-one'])->id, 'deployed_at' => now()->subDays(3)]);
        $deployment = Deployment::factory()->create(['environment_id' => $this->production->id, 'release_id' => Release::factory()->create(['project_id' => $this->project->id, 'version' => 'abc1234'])->id,
            'source' => 'deploy', 'deployment_key' => DeploymentMarkers::keyFor(77), 'deployed_at' => now()->subHour()]);
        TelemetryEvent::factory()->create(['environment_id' => $this->production->id, 'trace_id' => 'late-trace', 'occurred_at' => now()]);

        $this->actingAs($this->owner)->getJson("{$base}/traces/late-trace")->assertOk()->assertJsonPath('deployment.version', 'abc1234')->assertJsonPath('deployment.buildId', 77)->assertDontSee('old-one');
        $this->actingAs($this->owner)->getJson("{$base}/traces/early-trace")->assertOk()->assertJsonPath('deployment.version', 'old-one')->assertJsonPath('deployment.buildId', null);
        $this->actingAs($this->owner)->getJson("{$base}/deployments/{$deployment->id}")->assertOk()->assertJsonPath('deployment.releaseId', $deployment->release_id)->assertJsonPath('deployment.buildId', 77);
    }

    /**
     * Issues are resolved snoozed and assigned by members but not viewers.
     */
    public function test_issues_are_resolved_snoozed_and_assigned_by_members_but_not_viewers(): void
    {
        $member = User::factory()->create();
        $this->addMember($this->project, $member, AccountRole::Member);
        $viewer = User::factory()->create();
        $this->addMember($this->project, $viewer, AccountRole::Viewer);
        $issue = Issue::factory()->for($this->project)->create(['title' => 'Timeout talking to the bank']);
        $url = "/api/app/projects/{$this->project->id}/monitoring/issues/{$issue->id}";

        $this->actingAs($member)->patchJson($url, ['action' => 'snooze', 'version' => 0, 'snooze_minutes' => 60])->assertJsonRedirect($url);
        $this->assertSame(IssueStatus::Snoozed, $this->reload($issue)->status);
        $this->actingAs($member)->patchJson($url, ['action' => 'assign', 'version' => 1, 'assignee_id' => $viewer->id])->assertJsonValidationErrors('assignee_id');
        $this->actingAs($member)->patchJson($url, ['action' => 'assign', 'version' => 1, 'assignee_id' => $member->id])->assertJsonRedirect($url);
        $this->actingAs($member)->patchJson($url, ['action' => 'resolve', 'version' => 2, 'note' => 'Bank fixed their side.'])->assertJsonRedirect($url);
        $this->actingAs($viewer)->patchJson($url, ['action' => 'reopen', 'version' => 3])->assertForbidden();
        $this->actingAs($member)->patchJson($url, ['action' => 'reopen', 'version' => 2])->assertStatus(409);

        $issue->refresh();
        $this->assertSame(IssueStatus::Resolved, $issue->status);
        $this->assertSame($member->id, $issue->assignee_id);
        $this->actingAs($viewer)->getJson($url)->assertOk()->assertSee('Bank fixed their side.')->assertJsonPath('canUpdate', false);
    }

    /**
     * Ingest keys are created shown once replaced and revoked.
     */
    public function test_ingest_keys_are_created_shown_once_replaced_and_revoked(): void
    {
        $base = "/api/app/projects/{$this->project->id}/monitoring";

        $setup = $this->actingAs($this->owner)->getJson("{$base}/setup")->assertOk();
        $this->assertSame([[]], array_values(array_unique(array_map(fn (array $environment): array => $environment['tokens'], $setup->json('environments')), SORT_REGULAR)));
        $this->assertStringContainsString(route('api.ingest'), (string) json_encode($setup->json('guide'), JSON_UNESCAPED_SLASHES));
        $key = $this->actingAs($this->owner)->postJson("{$base}/environments/{$this->production->id}/keys", ['name' => 'Laravel app', 'expires_in_days' => 90])
            ->assertJsonRedirect("{$base}/setup")->json('secrets.ingest_key');
        $token = IngestToken::query()->sole();
        $this->assertSame('Laravel app', $token->name);
        $this->assertNotNull($token->expires_at);
        $this->assertMatchesRegularExpression('/\Abcn_[A-Za-z0-9]{64}\z/', (string) $key);
        $this->assertSame(hash('sha256', (string) $key), $token->token_hash);
        $this->actingAs($this->owner)->getJson("{$base}/setup")->assertOk()->assertDontSee((string) $key);

        $this->actingAs($this->owner)->postJson("{$base}/keys/{$token->id}/rotate")->assertJsonRedirect("{$base}/setup");
        $this->assertNotNull($this->reload($token)->revoked_at);
        $replacement = IngestToken::query()->whereNull('revoked_at')->sole();
        $this->actingAs($this->owner)->deleteJson("{$base}/keys/{$replacement->id}")->assertJsonRedirect("{$base}/setup");
        $this->assertNotNull($this->reload($replacement)->revoked_at);

        $viewer = User::factory()->create();
        $this->addMember($this->project, $viewer, AccountRole::Viewer);
        $this->actingAs($viewer)->postJson("{$base}/environments/{$this->production->id}/keys", ['name' => 'Nope'])->assertForbidden();
        $foreign = Environment::factory()->create();
        $this->actingAs($this->owner)->postJson("{$base}/environments/{$foreign->id}/keys", ['name' => 'Nope'])->assertNotFound();

        $this->assertSame(
            [AuditAction::IngestTokenCreated, AuditAction::IngestTokenRotated, AuditAction::IngestTokenRevoked],
            AuditEntry::query()->where('project_id', $this->project->id)->orderBy('id')->pluck('action')->all(),
        );
    }

    /**
     * Failed deliveries are listed and retried.
     */
    public function test_failed_deliveries_are_listed_and_retried(): void
    {
        $receipt = IngestReceipt::factory()->failed()->create(['environment_id' => $this->production->id, 'account_id' => $this->project->account_id]);
        $receipt->ingestPayload()->create(['payload' => [['identity_id' => 1, 'event' => ['type' => 'log']]]]);
        $base = "/api/app/projects/{$this->project->id}/monitoring";

        $this->actingAs($this->owner)->getJson("{$base}/environments/{$this->production->id}/deliveries")->assertOk()->assertJsonPath('receipts.0.retryable', true)->assertJsonPath('canRetry', true);
        $this->actingAs($this->owner)->postJson("{$base}/ingest-deliveries/{$receipt->id}/retry")
            ->assertJsonRedirect("{$base}/environments/{$this->production->id}/deliveries");
        // Queued again under a new generation (the test queue runs it at once; this stored payload can't be processed).
        $this->assertSame(2, $this->reload($receipt)->generation);
    }

    /**
     * Deployments are recorded by hand and compared.
     */
    public function test_deployments_are_recorded_by_hand_and_compared(): void
    {
        $base = "/api/app/projects/{$this->project->id}/monitoring";
        $payload = ['environment_id' => $this->production->id, 'deployment_id' => '9f2f8a4e-5a0e-4b8e-9c7e-8b9f6f1c2d3e', 'version' => '3.0.0', 'service' => 'shop'];

        $this->actingAs($this->owner)->getJson("{$base}/releases")->assertOk()->assertJsonPath('canRecord', true);
        $this->actingAs($this->owner)->postJson("{$base}/deployments", [...$payload, 'environment_id' => Environment::factory()->create()->id])->assertJsonValidationErrors('environment_id');
        $this->actingAs($this->owner)->postJson("{$base}/deployments", $payload)->assertSuccessful();
        $deployment = Deployment::query()->sole();
        $this->assertSame($this->owner->id, $deployment->actor_id);
        $this->assertSame('manual', $deployment->source);
        $this->actingAs($this->owner)->postJson("{$base}/deployments", $payload)->assertJsonRedirect("{$base}/deployments/{$deployment->id}");
        $this->assertDatabaseCount('deployments', 1);
        $this->actingAs($this->owner)->getJson("{$base}/deployments/{$deployment->id}")->assertOk()->assertJsonPath('deployment.version', '3.0.0');

        $viewer = User::factory()->create();
        $this->addMember($this->project, $viewer, AccountRole::Viewer);
        $this->actingAs($viewer)->postJson("{$base}/deployments", [...$payload, 'deployment_id' => '1f2f8a4e-5a0e-4b8e-9c7e-8b9f6f1c2d3e'])->assertForbidden();
        $this->actingAs($viewer)->getJson("{$base}/releases")->assertOk()->assertJsonPath('canRecord', false);
    }
}
