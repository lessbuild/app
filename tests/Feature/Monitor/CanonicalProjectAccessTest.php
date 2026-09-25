<?php

namespace Tests\Feature\Monitor;

use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectEnvironment;
use App\Core\Models\ProjectMembership;
use App\Core\Models\ProjectProduct;
use App\Core\Models\ProjectResource;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Core\Models\WorkspaceMembership;
use App\Core\Models\WorkspaceProductAccess;
use App\Core\Services\Projects\ManageCanonicalProjectMembership;
use App\Modules\Monitor\Console\Commands\SendIssueDigest;
use App\Modules\Monitor\Http\Controllers\EventController;
use App\Modules\Monitor\Http\Controllers\TraceEventController;
use App\Modules\Monitor\Models\AlertDelivery;
use App\Modules\Monitor\Models\AlertRule;
use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\AuditLog;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\Incident;
use App\Modules\Monitor\Models\Issue;
use App\Modules\Monitor\Models\IssueDigestDelivery;
use App\Modules\Monitor\Models\IssueDigestPreference;
use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Models\StatusPage;
use App\Modules\Monitor\Models\TelemetryEvent;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use App\Modules\Monitor\Notifications\IssueDigestNotification;
use App\Modules\Monitor\Services\Core\MonitorWorkspaceSearchProvider;
use App\Modules\Monitor\Services\CreateIngestToken;
use App\Modules\Monitor\Services\DeliverIssueDigest;
use App\Modules\Monitor\Services\ExportWorkspaceData;
use App\Modules\Monitor\Services\IssueDigestHistory;
use App\Modules\Monitor\Services\IssueDigestReport;
use App\Modules\Monitor\Services\Telemetry\CollectionHealthSummary;
use App\Modules\Monitor\Services\Telemetry\DashboardMetrics;
use App\Modules\Monitor\Services\Telemetry\ServiceDependencyMap;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/** Authored for the deferred plan-wide regression run. */
final class CanonicalProjectAccessTest extends TestCase
{
    private PlatformUser $owner;

    private PlatformUser $principal;

    private CoreWorkspace $coreWorkspace;

    private WorkspaceMembership $membership;

    private User $user;

    private Workspace $workspace;

    private Project $project;

    private Application $restricted;

    private Application $allowed;

    private Environment $restrictedEnvironment;

    private Environment $allowedEnvironment;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['core', 'monitor'] as $connection) {
            config(["database.connections.{$connection}.database" => ':memory:']);
            DB::purge($connection);
            $this->assertSame(0, Artisan::call('platform:migrate', ['module' => $connection]));
        }
        config(['platform.products.monitor.auth_authority' => 'core']);
        foreach (['Workspace', 'Application', 'Environment', 'Monitor', 'AlertRule', 'Incident', 'AlertDelivery'] as $model) {
            Gate::policy('App\\Modules\\Monitor\\Models\\'.$model, 'App\\Modules\\Monitor\\Policies\\'.$model.'Policy');
        }

        $this->owner = $this->platformUser('owner');
        $this->principal = $this->platformUser('member');
        $this->coreWorkspace = CoreWorkspace::query()->create([
            'owner_user_id' => $this->owner->getKey(), 'name' => 'Studio', 'slug' => 'studio', 'status' => 'active',
        ]);
        WorkspaceMembership::query()->create([
            'workspace_id' => $this->coreWorkspace->getKey(), 'user_id' => $this->owner->getKey(), 'role' => 'owner', 'status' => 'active',
        ]);
        $this->membership = WorkspaceMembership::query()->create([
            'workspace_id' => $this->coreWorkspace->getKey(), 'user_id' => $this->principal->getKey(), 'role' => 'member', 'status' => 'active',
        ]);
        WorkspaceProductAccess::query()->create([
            'membership_id' => $this->membership->getKey(), 'product' => 'monitor', 'role' => 'admin', 'status' => 'active',
        ]);
        $this->user = User::query()->forceCreate([
            'name' => 'Monitor admin', 'email' => 'monitor@example.test', 'password' => 'unused', 'email_verified_at' => now(),
        ]);
        $this->workspace = Workspace::query()->forceCreate([
            'owner_id' => $this->user->getKey(), 'name' => 'Studio', 'slug' => 'studio', 'plan' => 'free',
        ]);
        $this->workspace->members()->attach($this->user, ['role' => 'admin']);
        $this->identity('user', $this->user->getKey(), 'user', $this->principal->getKey());
        $this->identity('workspace', $this->workspace->getKey(), 'workspace', $this->coreWorkspace->getKey());
        [$this->project, $this->restricted, $this->restrictedEnvironment] = $this->application('restricted');
        [, $this->allowed, $this->allowedEnvironment] = $this->application('allowed');
    }

    public function test_core_revocation_removes_native_collections_and_direct_permissions_without_deleting_product_data(): void
    {
        $this->assertTrue(Gate::forUser($this->user)->allows('view', $this->restricted));
        $this->revoke();

        $this->assertFalse(Gate::forUser($this->user)->allows('view', $this->restricted));
        $this->assertFalse(Gate::forUser($this->user)->allows('update', $this->restrictedEnvironment));
        $this->assertSame([$this->allowed->id], $this->workspace->applications()->visibleTo($this->user, $this->workspace)->pluck('id')->all());
        $this->assertSame([$this->allowedEnvironment->id], Environment::forWorkspace($this->workspace)->visibleTo($this->user, $this->workspace)->pluck('id')->all());
        $this->assertSame(2, $this->workspace->applications()->count());
        $this->assertSame(2, Environment::forWorkspace($this->workspace)->count());
        $this->assertSame('admin', $this->workspace->roleFor($this->user));
        $this->assertTrue(Gate::forUser($this->user)->allows('update', $this->allowedEnvironment));

        $this->workspace->members()->updateExistingPivot($this->user->id, ['role' => 'viewer']);
        $this->assertTrue(Gate::forUser($this->user)->allows('view', $this->allowedEnvironment));
        $this->assertFalse(Gate::forUser($this->user)->allows('update', $this->allowedEnvironment));
    }

    public function test_unmapped_sources_and_legacy_authority_keep_native_access_but_inactive_mappings_do_not(): void
    {
        $unmapped = Application::query()->forceCreate(['workspace_id' => $this->workspace->id, 'name' => 'Unmapped', 'slug' => 'unmapped', 'accent' => 'sky', 'framework' => 'Laravel']);
        $this->revoke();
        $this->assertTrue(Gate::forUser($this->user)->allows('view', $unmapped));
        ProjectResource::query()->where('resource_type', 'application')->where('resource_id', (string) $this->allowed->id)->update(['status' => 'inactive']);
        $this->assertFalse(Gate::forUser($this->user)->allows('view', $this->allowed));
        $this->assertSame([$unmapped->id], Application::query()->visibleTo($this->user, $this->workspace)->pluck('id')->all());

        config(['platform.products.monitor.auth_authority' => 'legacy']);
        $this->assertTrue(Gate::forUser($this->user)->allows('view', $this->restricted));
        $this->assertTrue(Gate::forUser($this->user)->allows('view', $this->allowed));
        $this->assertSame(3, Application::query()->visibleTo($this->user, $this->workspace)->count());
    }

    public function test_paused_environments_remain_visible_and_resumable(): void
    {
        $canonical = ProjectEnvironment::query()->create([
            'project_id' => $this->project->getKey(), 'name' => 'Production', 'slug' => 'production', 'status' => 'paused',
        ]);
        ProjectResource::query()->create([
            'project_id' => $this->project->getKey(), 'environment_id' => $canonical->getKey(), 'product' => 'monitor',
            'resource_type' => 'environment', 'resource_id' => (string) $this->restrictedEnvironment->id, 'status' => 'paused',
        ]);
        $this->restrictedEnvironment->update(['status' => 'paused']);

        $this->assertTrue(Gate::forUser($this->user)->allows('view', $this->restrictedEnvironment));
        $this->assertTrue(Gate::forUser($this->user)->allows('update', $this->restrictedEnvironment));
        $this->assertTrue(Environment::query()->visibleTo($this->user, $this->workspace)->whereKey($this->restrictedEnvironment->id)->exists());
        $this->revoke();
        $this->assertFalse(Gate::forUser($this->user)->allows('view', $this->restrictedEnvironment));
    }

    public function test_environment_restrictions_do_not_block_authorized_siblings_but_prevent_application_wide_changes(): void
    {
        $canonical = ProjectEnvironment::query()->create([
            'project_id' => $this->project->getKey(), 'name' => 'Production', 'slug' => 'production', 'status' => 'active',
        ]);
        ProjectResource::query()->create([
            'project_id' => $this->project->getKey(), 'environment_id' => $canonical->getKey(), 'product' => 'monitor',
            'resource_type' => 'environment', 'resource_id' => (string) $this->allowedEnvironment->id, 'status' => 'active',
        ]);
        $sibling = Environment::query()->forceCreate([
            'application_id' => $this->allowed->id, 'name' => 'Staging', 'slug' => 'staging', 'status' => 'active',
        ]);
        $this->revoke();

        $this->assertTrue(Gate::forUser($this->user)->allows('view', $this->allowed));
        $this->assertFalse(Gate::forUser($this->user)->allows('view', $this->allowedEnvironment));
        $this->assertFalse(Gate::forUser($this->user)->allows('delete', $this->allowed));
        $this->assertTrue(Gate::forUser($this->user)->allows('update', $sibling));
        $this->assertSame([$sibling->id], $this->allowed->environments()->visibleTo($this->user, $this->workspace)->pluck('id')->all());
    }

    public function test_status_management_and_incident_deliveries_require_source_access(): void
    {
        $rule = AlertRule::factory()->create(['environment_id' => $this->restrictedEnvironment->id]);
        $incident = Incident::factory()->create(['alert_rule_id' => $rule->id]);
        $monitor = Monitor::factory()->create(['environment_id' => $this->restrictedEnvironment->id]);
        $page = StatusPage::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Private status', 'slug' => 'private-status', 'published' => false]);
        $page->components()->create(['monitor_id' => $monitor->id, 'label' => 'Private monitor', 'position' => 0]);
        $delivery = (new AlertDelivery)->forceFill(['workspace_id' => $this->workspace->id, 'incident_id' => $incident->id])->setRelation('workspace', $this->workspace)->setRelation('incident', $incident);
        $testDelivery = (new AlertDelivery)->forceFill(['workspace_id' => $this->workspace->id, 'incident_id' => null])->setRelation('workspace', $this->workspace);
        $this->revoke();

        $this->assertFalse($this->workspace->statusPages()->visibleTo($this->user, $this->workspace)->whereKey($page->id)->exists());
        $this->assertFalse(Gate::forUser($this->user)->allows('view', $delivery));
        $this->assertFalse(Gate::forUser($this->user)->allows('update', $delivery));
        $this->assertTrue(Gate::forUser($this->user)->allows('view', $testDelivery));
    }

    public function test_incident_queries_and_core_search_check_every_populated_source(): void
    {
        $rule = AlertRule::factory()->create(['environment_id' => $this->restrictedEnvironment->id]);
        $monitor = Monitor::factory()->create(['environment_id' => $this->allowedEnvironment->id]);
        $mixed = Incident::factory()->create(['alert_rule_id' => $rule->id, 'monitor_id' => $monitor->id]);
        $allowedRule = AlertRule::factory()->create(['environment_id' => $this->allowedEnvironment->id]);
        $visible = Incident::factory()->create(['alert_rule_id' => $allowedRule->id]);
        $this->revoke();

        $this->assertFalse(Gate::forUser($this->user)->allows('view', $mixed));
        $this->assertSame([$visible->id], Incident::forWorkspace($this->workspace)->visibleTo($this->user, $this->workspace)->pluck('id')->all());
        Route::get('/monitor/incidents/{incident}', fn () => response(''))->name('monitor.incidents.show');
        $results = app(MonitorWorkspaceSearchProvider::class)->search($this->principal, $this->coreWorkspace, 'open');
        $this->assertCount(1, $results);
        $this->assertStringContainsString('/monitor/incidents/'.$visible->id, $results[0]->url);
    }

    public function test_project_revocation_filters_event_aggregates_and_reports(): void
    {
        $this->event($this->restrictedEnvironment, 'private-service');
        $this->event($this->allowedEnvironment, 'public-service');
        $this->revoke();

        $this->assertSame(1, app(DashboardMetrics::class)->forWorkspace($this->workspace, '24h', $this->user)['eventCount']);
        $this->assertSame(1, app(CollectionHealthSummary::class)->forWorkspace($this->workspace, $this->user)['total']);
        $map = app(ServiceDependencyMap::class)->forWorkspace($this->workspace, '24h', principal: $this->user);
        $this->assertSame(1, $map['records']);
        $this->assertSame(['public-service'], array_column($map['services'], 'name'));
        $this->assertSame(2, TelemetryEvent::forWorkspace($this->workspace)->count());
    }

    public function test_event_and_trace_detail_routes_reject_a_revoked_project(): void
    {
        $event = $this->event($this->restrictedEnvironment, 'private-service');
        $this->revoke();
        Route::middleware(['web', function (Request $request, $next) {
            $request->attributes->set('platform_user', $this->principal);

            return $next($request);
        }])->group(function (): void {
            Route::get('/monitor-access/events/{event}', [EventController::class, 'show']);
            Route::get('/monitor-access/traces/{trace}/{event}', [TraceEventController::class, 'show']);
        });

        $this->actingAs($this->user)->get('/monitor-access/events/'.$event->id)->assertNotFound();
        $this->get('/monitor-access/traces/'.$event->trace_id.'/'.$event->id)->assertNotFound();
    }

    public function test_token_issuance_rechecks_the_actor_and_leaves_revoked_data_intact(): void
    {
        $this->revoke();
        try {
            app(CreateIngestToken::class)->create($this->restrictedEnvironment, $this->user, 'Denied token');
            $this->fail('A revoked project must not issue new ingestion credentials.');
        } catch (AuthorizationException) {
            $this->assertSame(0, $this->restrictedEnvironment->ingestTokens()->count());
            $this->assertDatabaseHas('environments', ['id' => $this->restrictedEnvironment->id], 'monitor');
        }
    }

    public function test_workspace_export_omits_revoked_applications_environments_and_telemetry(): void
    {
        $this->event($this->restrictedEnvironment, 'private-service');
        $this->event($this->allowedEnvironment, 'public-service');
        $this->revoke();
        $output = fopen('php://temp', 'w+');
        try {
            app(ExportWorkspaceData::class)->write($this->workspace, $output, $this->user);
            rewind($output);
            $records = collect(explode("\n", trim(stream_get_contents($output))))->map(fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR));
            $this->assertSame([$this->allowed->id], $records->where('type', 'application')->pluck('data.id')->values()->all());
            $this->assertSame([$this->allowedEnvironment->id], $records->where('type', 'environment')->pluck('data.id')->values()->all());
            $this->assertSame(['public-service'], $records->where('type', 'telemetry_event')->pluck('data.service')->values()->all());
        } finally {
            fclose($output);
        }
    }

    public function test_daily_command_builds_a_separate_digest_for_each_current_recipient(): void
    {
        $this->configureDigests();
        Notification::fake();
        $this->digestIssue($this->restricted, $this->restrictedEnvironment, 'Restricted issue');
        $this->digestIssue($this->allowed, $this->allowedEnvironment, 'Allowed issue');
        $viewer = $this->digestRecipient();
        $this->revoke();
        [$from, $until] = $this->digestPeriod();

        $this->assertSame(0, Artisan::call(SendIssueDigest::class, [
            '--workspace' => $this->workspace->id, '--from' => $from->toISOString(), '--until' => $until->toISOString(),
        ]));
        Notification::assertSentTo($this->user, IssueDigestNotification::class, fn ($notification): bool => array_column($notification->digest['new_issues'], 'title') === ['Allowed issue']
            && $notification->digest['open_count'] === 1 && $notification->digest['critical_open_count'] === 1);
        Notification::assertSentTo($viewer, IssueDigestNotification::class, fn ($notification): bool => array_column($notification->digest['new_issues'], 'title') === ['Restricted issue']
            && $notification->digest['open_count'] === 1);
        Notification::assertCount(2);
        $this->assertSame(2, IssueDigestDelivery::query()->where('status', 'sent')->count());
        $this->assertSame(2, Issue::forWorkspace($this->workspace)->count());
        $this->assertSame('viewer', $this->workspace->roleFor($viewer));
    }

    public function test_delivery_rechecks_permissions_after_claim_and_completed_periods_are_not_resent(): void
    {
        $this->configureDigests();
        Notification::fake();
        $this->digestIssue($this->restricted, $this->restrictedEnvironment, 'Restricted issue');
        $this->digestIssue($this->allowed, $this->allowedEnvironment, 'Allowed issue');
        IssueDigestDelivery::created(fn () => $this->revoke());
        [$from, $until] = $this->digestPeriod();

        $this->assertSame('sent', app(DeliverIssueDigest::class)->deliver($this->workspace, $this->user, $from, $until));
        $this->assertSame('skipped', app(DeliverIssueDigest::class)->deliver($this->workspace, $this->user, $from, $until));
        Notification::assertSentTo($this->user, IssueDigestNotification::class, fn ($notification): bool => array_column($notification->digest['new_issues'], 'title') === ['Allowed issue']
            && $notification->digest['open_count'] === 1 && ! array_key_exists('source_scope', $notification->digest)
            && $notification->toMail($this->user)->markdown === 'monitor::mail.issue-digest');
        Notification::assertCount(1);
        $delivery = IssueDigestDelivery::query()->sole();
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame(1, $delivery->new_count);
        $this->assertSame([['application_id' => $this->allowed->id, 'environment_id' => $this->allowedEnvironment->id]], $delivery->source_scope['sources']);
    }

    public function test_a_revocation_while_persisting_the_payload_cancels_transport_and_allows_a_filtered_retry(): void
    {
        $this->configureDigests();
        Notification::fake();
        $this->digestIssue($this->restricted, $this->restrictedEnvironment, 'Restricted issue');
        $this->digestIssue($this->allowed, $this->allowedEnvironment, 'Allowed issue');
        $revoked = false;
        IssueDigestDelivery::saving(function (IssueDigestDelivery $delivery) use (&$revoked): void {
            if ($delivery->exists && ! $revoked) {
                $revoked = true;
                $this->revoke();
            }
        });
        [$from, $until] = $this->digestPeriod();
        $this->assertSame('skipped', app(DeliverIssueDigest::class)->deliver($this->workspace, $this->user, $from, $until));
        Notification::assertNothingSent();
        $this->assertSame('source_access_changed', IssueDigestDelivery::query()->sole()->last_error_code);
        $this->assertSame('sent', app(DeliverIssueDigest::class)->deliver($this->workspace, $this->user, $from, $until));
        Notification::assertSentTo($this->user, IssueDigestNotification::class, fn ($notification): bool => array_column($notification->digest['new_issues'], 'title') === ['Allowed issue']);
        $this->assertSame(2, IssueDigestDelivery::query()->sole()->attempts);
    }

    public function test_failed_digest_retries_use_current_sources_and_counts(): void
    {
        $this->configureDigests();
        Notification::fake();
        $this->digestIssue($this->restricted, $this->restrictedEnvironment, 'Restricted issue');
        $this->digestIssue($this->allowed, null, 'Application issue');
        [$from, $until] = $this->digestPeriod();
        $delivery = IssueDigestDelivery::factory()->create([
            'workspace_id' => $this->workspace->id, 'recipient_id' => $this->user->id,
            'period_start' => $from, 'period_end' => $until, 'status' => 'failed', 'attempts' => 1,
            'new_count' => 99, 'open_count' => 99, 'source_scope' => null, 'sent_at' => null,
        ]);
        $this->revoke();

        $this->assertSame('sent', app(DeliverIssueDigest::class)->deliver($this->workspace, $this->user, $from, $until));
        Notification::assertSentTo($this->user, IssueDigestNotification::class, fn ($notification): bool => array_column($notification->digest['new_issues'], 'title') === ['Application issue']);
        $this->assertSame(2, $delivery->fresh()->attempts);
        $this->assertSame(1, $delivery->fresh()->open_count);
        $this->assertSame(1, IssueDigestDelivery::query()->count());
    }

    public function test_no_digest_is_sent_when_source_access_or_recipient_eligibility_is_removed(): void
    {
        $this->configureDigests();
        Notification::fake();
        $this->digestIssue($this->restricted, $this->restrictedEnvironment, 'Restricted issue');
        $this->revoke();
        [$from, $until] = $this->digestPeriod();
        $deliver = app(DeliverIssueDigest::class);
        $this->assertSame('skipped', $deliver->deliver($this->workspace, $this->user, $from, $until));
        $this->digestIssue($this->allowed, $this->allowedEnvironment, 'Allowed issue');
        $this->user->forceFill(['email_verified_at' => null])->save();
        $this->assertSame('skipped', $deliver->deliver($this->workspace, $this->user, $from, $until));
        $this->user->forceFill(['email_verified_at' => now()])->save();
        $preference = IssueDigestPreference::query()->create([
            'workspace_id' => $this->workspace->id, 'user_id' => $this->user->id, 'enabled' => false, 'frequency' => 'off',
        ]);
        $this->assertSame('skipped', $deliver->deliver($this->workspace, $this->user, $from, $until));
        $preference->update(['enabled' => true, 'frequency' => 'daily']);
        $this->workspace->members()->detach($this->user);
        $this->assertSame('skipped', $deliver->deliver($this->workspace, $this->user, $from, $until));
        Notification::assertNothingSent();
        $this->assertSame(0, IssueDigestDelivery::query()->count());
    }

    public function test_history_hides_original_aggregate_counts_after_any_contributing_project_is_revoked(): void
    {
        $this->configureDigests();
        Notification::fake();
        $this->digestIssue($this->restricted, $this->restrictedEnvironment, 'Restricted issue');
        $this->digestIssue($this->allowed, $this->allowedEnvironment, 'Allowed issue');
        [$from, $until] = $this->digestPeriod();
        app(DeliverIssueDigest::class)->deliver($this->workspace, $this->user, $from, $until);
        $history = app(IssueDigestHistory::class);
        $this->assertTrue($history->forRecipient($this->workspace, $this->user)->sole()->summary_available);
        $this->revoke();

        $entry = $history->forRecipient($this->workspace, $this->user)->sole();
        $this->assertFalse($entry->summary_available);
        $this->assertNull($entry->new_count);
        $this->assertNull($entry->open_count);
        $this->assertNull($entry->source_scope);
        $this->assertSame('sent', $entry->status);
        $this->assertSame(2, IssueDigestDelivery::query()->sole()->open_count);
        $this->assertCount(0, $history->forRecipient($this->workspace, $this->digestRecipient()));
    }

    public function test_legacy_history_stays_available_and_core_history_without_provenance_fails_closed(): void
    {
        $entry = IssueDigestDelivery::factory()->create([
            'workspace_id' => $this->workspace->id, 'recipient_id' => $this->user->id, 'source_scope' => null, 'open_count' => 12,
        ]);
        $history = app(IssueDigestHistory::class);
        $this->assertFalse($history->forRecipient($this->workspace, $this->user)->sole()->summary_available);
        config(['platform.products.monitor.auth_authority' => 'legacy']);
        $this->assertSame(12, $history->forRecipient($this->workspace, $this->user)->sole()->open_count);
        config(['platform.products.monitor.auth_authority' => 'core']);
        $entry->update(['source_scope' => ['version' => 1, 'sources' => [['application_id' => $this->allowed->id, 'environment_id' => $this->restrictedEnvironment->id]]]]);
        $this->assertFalse($history->forRecipient($this->workspace, $this->user)->sole()->summary_available);
        $entry->update(['source_scope' => ['version' => 1, 'sources' => [['application_id' => $this->allowed->id, 'environment_id' => $this->allowedEnvironment->id]]]]);
        $this->assertTrue($history->forRecipient($this->workspace, $this->user)->sole()->summary_available);
        $this->membership->update(['status' => 'inactive']);
        $this->assertFalse($history->forRecipient($this->workspace, $this->user)->sole()->summary_available);
        $this->assertSame(12, $entry->fresh()->open_count);
    }

    public function test_report_tracks_sources_outside_the_sampled_lists_and_filters_resolved_snoozed_and_critical_counts(): void
    {
        $this->configureDigests();
        $this->digestIssue($this->restricted, $this->restrictedEnvironment, 'Old restricted critical')->update(['first_seen_at' => now()->subDays(5)]);
        $this->digestIssue($this->restricted, $this->restrictedEnvironment, 'Restricted resolved')->forceFill(['status' => 'resolved', 'resolved_at' => now()->subMinute()])->save();
        $this->digestIssue($this->restricted, $this->restrictedEnvironment, 'Restricted snoozed')->update(['status' => 'snoozed']);
        for ($i = 0; $i < 11; $i++) {
            $this->digestIssue($this->allowed, $this->allowedEnvironment, 'Allowed '.$i);
        }
        [$from, $until] = $this->digestPeriod();
        $report = app(IssueDigestReport::class);
        $before = $report->forRecipient($this->workspace, $this->user, $from, $until);
        $this->assertCount(10, $before['new_issues']);
        $this->assertCount(2, $before['source_scope']['sources']);
        $this->assertSame(12, $before['critical_open_count']);
        $this->assertSame(1, $before['snoozed_count']);
        $this->revoke();
        $after = $report->forRecipient($this->workspace, $this->user, $from, $until);
        $this->assertSame(11, $after['open_count']);
        $this->assertSame(11, $after['critical_open_count']);
        $this->assertSame(0, $after['snoozed_count']);
        $this->assertSame([], $after['resolved_issues']);
        $this->assertSame([['application_id' => $this->allowed->id, 'environment_id' => $this->allowedEnvironment->id]], $after['source_scope']['sources']);
    }

    public function test_historical_export_retains_archived_dependencies_but_still_enforces_current_membership(): void
    {
        $issue = $this->digestIssue($this->restricted, $this->restrictedEnvironment, 'Archived issue');
        $event = $this->event($this->restrictedEnvironment, 'archived-service');
        $rule = AlertRule::factory()->create(['environment_id' => $this->restrictedEnvironment->id]);
        $incident = Incident::factory()->create(['alert_rule_id' => $rule->id]);
        $log = AuditLog::query()->create([
            'workspace_id' => $this->workspace->id, 'action' => 'alert_rule.archived',
            'subject_type' => $rule->getMorphClass(), 'subject_id' => $rule->id, 'metadata' => ['label' => 'Archived rule'],
        ]);
        $canonical = ProjectEnvironment::query()->create([
            'project_id' => $this->project->getKey(), 'name' => 'Production', 'slug' => 'production', 'status' => 'archived',
        ]);
        ProjectResource::query()->create([
            'project_id' => $this->project->getKey(), 'environment_id' => $canonical->getKey(), 'product' => 'monitor',
            'resource_type' => 'environment', 'resource_id' => (string) $this->restrictedEnvironment->id, 'status' => 'archived',
        ]);
        ProjectResource::query()->where('resource_type', 'application')->where('resource_id', (string) $this->restricted->id)->update(['status' => 'archived']);
        $this->project->update(['status' => 'archived']);
        ProjectProduct::query()->where('project_id', $this->project->getKey())->update(['status' => 'inactive']);
        $rule->delete();
        $this->restrictedEnvironment->delete();
        $this->restricted->delete();

        $this->assertFalse(Gate::forUser($this->user)->allows('view', $this->restricted));
        $this->assertFalse(Gate::forUser($this->user)->allows('restore', $this->restricted));
        $this->assertSame([], Incident::query()->visibleTo($this->user, $this->workspace)->pluck('id')->all());
        $records = $this->exportRecords();
        foreach (['issue' => $issue, 'telemetry_event' => $event, 'incident' => $incident, 'audit_log' => $log] as $type => $model) {
            $this->assertContains($model->id, $records->where('type', $type)->pluck('data.id')->all());
        }
        $this->assertContains($this->restricted->id, $records->where('type', 'application')->pluck('data.id')->all());
        $this->assertContains($this->restrictedEnvironment->id, $records->where('type', 'environment')->pluck('data.id')->all());

        // Membership remains authoritative even when a project is archived.
        ProjectMembership::query()->where('project_id', $this->project->getKey())->where('user_id', $this->principal->getKey())->update(['status' => 'inactive']);
        $after = $this->exportRecords();
        foreach (['issue', 'telemetry_event', 'incident', 'audit_log'] as $type) {
            $this->assertSame([], $after->where('type', $type)->values()->all());
        }
        $this->assertSame([$this->allowed->id], $after->where('type', 'application')->pluck('data.id')->values()->all());
    }

    public function test_imported_audit_subject_names_remain_subject_to_project_access(): void
    {
        $denied = AuditLog::query()->create([
            'workspace_id' => $this->workspace->id, 'action' => 'application.updated',
            'subject_type' => 'App\\Models\\Application', 'subject_id' => $this->restricted->id, 'metadata' => ['label' => 'Restricted application'],
        ]);
        $allowed = AuditLog::query()->create([
            'workspace_id' => $this->workspace->id, 'action' => 'environment.updated',
            'subject_type' => 'App\\Models\\Environment', 'subject_id' => $this->allowedEnvironment->id, 'metadata' => ['label' => 'Allowed environment'],
        ]);
        $this->revoke();

        $this->assertSame([$allowed->id], AuditLog::forWorkspace($this->workspace)->visibleTo($this->user, $this->workspace)->pluck('id')->all());
        $this->assertSame([$allowed->id], $this->exportRecords()->where('type', 'audit_log')->pluck('data.id')->values()->all());
        $this->assertDatabaseHas('audit_logs', ['id' => $denied->id], 'monitor');
    }

    public function test_export_service_requires_a_current_native_admin(): void
    {
        $this->workspace->members()->updateExistingPivot($this->user->id, ['role' => 'viewer']);
        $this->expectException(AuthorizationException::class);
        $this->exportRecords();
    }

    public function test_export_without_a_current_core_context_writes_no_data_even_for_a_native_admin(): void
    {
        $this->membership->update(['status' => 'inactive']);
        foreach ([null, $this->user] as $principal) {
            $output = fopen('php://temp', 'w+');
            try {
                app(ExportWorkspaceData::class)->write($this->workspace, $output, $principal);
                $this->fail('A missing or revoked Core principal must not export workspace metadata.');
            } catch (HttpExceptionInterface $exception) {
                $this->assertSame(403, $exception->getStatusCode());
                $this->assertSame(0, ftell($output));
            } finally {
                fclose($output);
            }
        }
    }

    public function test_digest_skips_revoked_core_product_grants_and_keeps_legacy_source_behavior(): void
    {
        $this->configureDigests();
        Notification::fake();
        $this->digestIssue($this->allowed, $this->allowedEnvironment, 'Allowed issue');
        WorkspaceProductAccess::query()->where('membership_id', $this->membership->getKey())->update(['status' => 'inactive']);
        [$from, $until] = $this->digestPeriod();
        $this->assertSame('skipped', app(DeliverIssueDigest::class)->deliver($this->workspace, $this->user, $from, $until));
        Notification::assertNothingSent();
        $this->assertSame(0, IssueDigestDelivery::query()->count());

        config(['platform.products.monitor.auth_authority' => 'legacy']);
        $this->assertSame('sent', app(DeliverIssueDigest::class)->deliver($this->workspace, $this->user, $from, $until));
        Notification::assertSentTo($this->user, IssueDigestNotification::class);
        $this->assertTrue(app(IssueDigestHistory::class)->forRecipient($this->workspace, $this->user)->sole()->summary_available);
    }

    private function configureDigests(): void
    {
        config(['monitor.beacon.plan_authority' => 'legacy', 'monitor.beacon.plans.free.issue_digest' => true]);
        Artisan::registerCommand(app(SendIssueDigest::class));
        Route::get('/monitor/issues', fn () => response(''))->name('monitor.issues.index');
        Route::get('/monitor/issues/{issue}', fn () => response(''))->name('monitor.issues.show');
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function digestPeriod(): array
    {
        $until = CarbonImmutable::now('UTC')->addMinute();

        return [$until->subDay(), $until];
    }

    private function digestIssue(Application $application, ?Environment $environment, string $title): Issue
    {
        return Issue::factory()->create([
            'application_id' => $application->id, 'environment_id' => $environment?->id,
            'title' => $title, 'severity' => 'critical', 'first_seen_at' => now()->subMinute(), 'last_seen_at' => now()->subMinute(),
        ]);
    }

    private function digestRecipient(): User
    {
        $principal = $this->platformUser('digest-viewer');
        $membership = WorkspaceMembership::query()->create([
            'workspace_id' => $this->coreWorkspace->getKey(), 'user_id' => $principal->getKey(), 'role' => 'member', 'status' => 'active',
        ]);
        WorkspaceProductAccess::query()->create(['membership_id' => $membership->getKey(), 'product' => 'monitor', 'role' => 'viewer', 'status' => 'active']);
        ProjectMembership::query()->create(['project_id' => $this->project->getKey(), 'user_id' => $principal->getKey(), 'role' => 'viewer', 'status' => 'active']);
        $recipient = User::query()->forceCreate([
            'name' => 'Digest viewer', 'email' => 'digest-viewer@monitor.example.test', 'password' => 'unused', 'email_verified_at' => now(),
        ]);
        $this->workspace->members()->attach($recipient, ['role' => 'viewer']);
        $this->identity('user', $recipient->getKey(), 'user', $principal->getKey());
        IssueDigestPreference::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $recipient->id, 'enabled' => true, 'frequency' => 'daily']);

        return $recipient;
    }

    private function exportRecords(): Collection
    {
        $output = fopen('php://temp', 'w+');
        try {
            app(ExportWorkspaceData::class)->write($this->workspace, $output, $this->user);
            rewind($output);

            return collect(explode("\n", trim(stream_get_contents($output))))->map(fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR));
        } finally {
            fclose($output);
        }
    }

    private function revoke(): void
    {
        app(ManageCanonicalProjectMembership::class)->revoke($this->owner, $this->coreWorkspace, $this->project, $this->membership->getKey());
    }

    private function platformUser(string $name): PlatformUser
    {
        return PlatformUser::query()->create([
            'name' => $name, 'email' => $name.'@example.test', 'email_normalized' => $name.'@example.test', 'password' => 'unused', 'status' => 'active',
        ]);
    }

    private function identity(string $entity, int $id, string $canonicalEntity, string $canonicalId): void
    {
        LegacyIdentityMap::query()->create([
            'source_product' => 'monitor', 'source_entity' => $entity, 'source_id' => (string) $id,
            'canonical_entity' => $canonicalEntity, 'canonical_id' => $canonicalId, 'status' => 'reconciled',
        ]);
    }

    /** @return array{Project, Application, Environment} */
    private function application(string $name): array
    {
        $project = Project::query()->create([
            'workspace_id' => $this->coreWorkspace->getKey(), 'name' => $name, 'slug' => $name, 'status' => 'active',
        ]);
        ProjectMembership::query()->create(['project_id' => $project->getKey(), 'user_id' => $this->principal->getKey(), 'role' => 'member', 'status' => 'active']);
        ProjectProduct::query()->create(['project_id' => $project->getKey(), 'product' => 'monitor', 'status' => 'active']);
        $application = Application::query()->forceCreate(['workspace_id' => $this->workspace->id, 'name' => $name, 'slug' => $name, 'accent' => 'sky', 'framework' => 'Laravel']);
        $environment = Environment::query()->forceCreate(['application_id' => $application->id, 'name' => 'Production', 'slug' => 'production', 'status' => 'active']);
        ProjectResource::query()->create([
            'project_id' => $project->getKey(), 'product' => 'monitor', 'resource_type' => 'application', 'resource_id' => (string) $application->id, 'status' => 'active',
        ]);

        return [$project, $application, $environment];
    }

    private function event(Environment $environment, string $service): TelemetryEvent
    {
        return TelemetryEvent::query()->forceCreate([
            'environment_id' => $environment->id, 'dedupe_key' => hash('sha256', $service), 'trace_id' => $service,
            'span_id' => 'span-'.$service, 'type' => 'request', 'severity' => 'info', 'name' => 'GET /health',
            'service' => $service, 'duration_ms' => 10, 'status_code' => 200, 'occurred_at' => now()->subMinute(), 'payload' => [], 'attributes' => [],
        ]);
    }
}
