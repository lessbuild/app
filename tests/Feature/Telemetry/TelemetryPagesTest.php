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
use App\Models\User;
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
        $base = "/projects/{$this->project->id}/monitoring";
        $issue = Issue::query()->sole();

        $this->actingAs($this->owner)->get("{$base}/issues")->assertOk()->assertSee('Payment declined');
        $this->actingAs($this->owner)->get("{$base}/issues/{$issue->id}")->assertOk()->assertSee('Payment declined')->assertSee(__('Resolve'));
        $this->actingAs($this->owner)->get("{$base}/events")->assertOk()->assertSee('GET /checkout');
        $this->actingAs($this->owner)->get("{$base}/events?type=exception")->assertOk()->assertSee('PaymentDeclined')->assertDontSee('GET /checkout');
        $this->actingAs($this->owner)->get("{$base}/traces/trace-pages")->assertOk()->assertSee('GET /checkout');
        $this->actingAs($this->owner)->get("{$base}/traces/unknown-trace")->assertNotFound();
        $this->actingAs($this->owner)->get("{$base}/dependencies")->assertOk();
        $this->actingAs($this->owner)->get("{$base}/releases")->assertOk()->assertSee('2.4.0');
        $release = $this->project->releases()->sole();
        $this->actingAs($this->owner)->get("{$base}/releases/{$release->id}")->assertOk()->assertSee('Payment declined');
        $event = $issue->telemetryEvents()->firstOrFail();
        $this->actingAs($this->owner)->get("{$base}/events/{$event->id}")->assertOk()->assertSee('PaymentDeclined');

        $foreign = Project::factory()->withServices(['monitoring'])->create();
        $this->actingAs($this->owner)->get("/projects/{$foreign->id}/monitoring/issues/{$issue->id}")->assertNotFound();
        $this->actingAs($this->owner)->get("/projects/{$this->project->id}/monitoring/issues/".Issue::factory()->create()->id)->assertNotFound();
    }

    public function test_issues_are_resolved_snoozed_and_assigned_by_members_but_not_viewers(): void
    {
        $member = User::factory()->create();
        $this->addMember($this->project, $member, AccountRole::Member);
        $viewer = User::factory()->create();
        $this->addMember($this->project, $viewer, AccountRole::Viewer);
        $issue = Issue::factory()->for($this->project)->create(['title' => 'Timeout talking to the bank']);
        $url = "/projects/{$this->project->id}/monitoring/issues/{$issue->id}";

        $this->actingAs($member)->patch($url, ['action' => 'snooze', 'version' => 0, 'snooze_minutes' => 60])->assertRedirect($url);
        $this->assertSame(IssueStatus::Snoozed, $this->reload($issue)->status);
        $this->actingAs($member)->patch($url, ['action' => 'assign', 'version' => 1, 'assignee_id' => $viewer->id])->assertSessionHasErrors('assignee_id');
        $this->actingAs($member)->patch($url, ['action' => 'assign', 'version' => 1, 'assignee_id' => $member->id])->assertRedirect($url);
        $this->actingAs($member)->patch($url, ['action' => 'resolve', 'version' => 2, 'note' => 'Bank fixed their side.'])->assertRedirect($url);
        $this->actingAs($viewer)->patch($url, ['action' => 'reopen', 'version' => 3])->assertForbidden();
        $this->actingAs($member)->patch($url, ['action' => 'reopen', 'version' => 2])->assertStatus(409);

        $issue->refresh();
        $this->assertSame(IssueStatus::Resolved, $issue->status);
        $this->assertSame($member->id, $issue->assignee_id);
        $this->actingAs($viewer)->get($url)->assertOk()->assertSee('Bank fixed their side.')->assertDontSee(__('Snooze for'));
    }

    public function test_ingest_keys_are_created_shown_once_replaced_and_revoked(): void
    {
        $base = "/projects/{$this->project->id}/monitoring";

        $this->actingAs($this->owner)->get("{$base}/setup")->assertOk()->assertSee(__('No ingest key'))->assertSee(route('api.ingest'));
        $this->actingAs($this->owner)->post("{$base}/environments/{$this->production->id}/keys", ['name' => 'Laravel app', 'expires_in_days' => 90])
            ->assertRedirect("{$base}/setup");
        $token = IngestToken::query()->sole();
        $this->assertSame('Laravel app', $token->name);
        $this->assertNotNull($token->expires_at);
        $page = $this->actingAs($this->owner)->withSession(['issued_ingest_key' => session('issued_ingest_key')])->get("{$base}/setup")->assertOk();
        preg_match('/bcn_[A-Za-z0-9]{64}/', (string) $page->getContent(), $match);
        $this->assertSame(hash('sha256', $match[0] ?? ''), $token->token_hash);
        $this->actingAs($this->owner)->get("{$base}/setup")->assertDontSee($match[0] ?? 'missing');

        $this->actingAs($this->owner)->post("{$base}/keys/{$token->id}/rotate")->assertRedirect("{$base}/setup");
        $this->assertNotNull($this->reload($token)->revoked_at);
        $replacement = IngestToken::query()->whereNull('revoked_at')->sole();
        $this->actingAs($this->owner)->delete("{$base}/keys/{$replacement->id}")->assertRedirect("{$base}/setup");
        $this->assertNotNull($this->reload($replacement)->revoked_at);

        $viewer = User::factory()->create();
        $this->addMember($this->project, $viewer, AccountRole::Viewer);
        $this->actingAs($viewer)->post("{$base}/environments/{$this->production->id}/keys", ['name' => 'Nope'])->assertForbidden();
        $foreign = Environment::factory()->create();
        $this->actingAs($this->owner)->post("{$base}/environments/{$foreign->id}/keys", ['name' => 'Nope'])->assertNotFound();

        $this->assertSame(
            [AuditAction::IngestTokenCreated, AuditAction::IngestTokenRotated, AuditAction::IngestTokenRevoked],
            AuditEntry::query()->where('project_id', $this->project->id)->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_failed_deliveries_are_listed_and_retried(): void
    {
        $receipt = IngestReceipt::factory()->failed()->create(['environment_id' => $this->production->id, 'account_id' => $this->project->account_id]);
        $receipt->ingestPayload()->create(['payload' => [['identity_id' => 1, 'event' => ['type' => 'log']]]]);
        $base = "/projects/{$this->project->id}/monitoring";

        $this->actingAs($this->owner)->get("{$base}/environments/{$this->production->id}/deliveries")->assertOk()->assertSee(__('Retry'));
        $this->actingAs($this->owner)->post("{$base}/ingest-deliveries/{$receipt->id}/retry")
            ->assertRedirect("{$base}/environments/{$this->production->id}/deliveries");
        // Queued again under a new generation (the test queue runs it at once; this stored payload can't be processed).
        $this->assertSame(2, $this->reload($receipt)->generation);
    }

    public function test_deployments_are_recorded_by_hand_and_compared(): void
    {
        $base = "/projects/{$this->project->id}/monitoring";
        $payload = ['environment_id' => $this->production->id, 'deployment_id' => '9f2f8a4e-5a0e-4b8e-9c7e-8b9f6f1c2d3e', 'version' => '3.0.0', 'service' => 'shop'];

        $this->actingAs($this->owner)->get("{$base}/releases")->assertOk()->assertSee(__('Record deployment'));
        $this->actingAs($this->owner)->post("{$base}/deployments", [...$payload, 'environment_id' => Environment::factory()->create()->id])->assertSessionHasErrors('environment_id');
        $this->actingAs($this->owner)->post("{$base}/deployments", $payload)->assertRedirect();
        $deployment = Deployment::query()->sole();
        $this->assertSame($this->owner->id, $deployment->actor_id);
        $this->assertSame('manual', $deployment->source);
        $this->actingAs($this->owner)->post("{$base}/deployments", $payload)->assertRedirect("{$base}/deployments/{$deployment->id}");
        $this->assertDatabaseCount('deployments', 1);
        $this->actingAs($this->owner)->get("{$base}/deployments/{$deployment->id}")->assertOk()->assertSee('3.0.0');

        $viewer = User::factory()->create();
        $this->addMember($this->project, $viewer, AccountRole::Viewer);
        $this->actingAs($viewer)->post("{$base}/deployments", [...$payload, 'deployment_id' => '1f2f8a4e-5a0e-4b8e-9c7e-8b9f6f1c2d3e'])->assertForbidden();
        $this->actingAs($viewer)->get("{$base}/releases")->assertOk()->assertDontSee(__('Record deployment'));
    }
}
