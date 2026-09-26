<?php

namespace Tests\Modules\Analytics\Feature;

use App\Modules\Analytics\Enums\WorkspaceRole;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Analytics\Models\Goal;
use App\Modules\Analytics\Models\IngestionBatch;
use App\Modules\Analytics\Models\Invitation;
use App\Modules\Analytics\Models\ReportDailyAggregate;
use App\Modules\Analytics\Models\ReportExport;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Models\User;
use App\Modules\Analytics\Models\Visit;
use App\Modules\Analytics\Models\Workspace;
use App\Modules\Analytics\Models\WorkspaceUsagePeriod;
use Illuminate\Support\Str;
use Tests\Modules\Analytics\RefreshAnalyticsDatabase;
use Tests\TestCase;

final class WorkspaceDataExportTest extends TestCase
{
    use RefreshAnalyticsDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.products.analytics.auth_authority' => 'legacy']);
    }

    public function test_workspace_owner_can_stream_related_analytics_data_without_secrets_or_other_tenant_records(): void
    {
        [$owner, $workspace, $site] = $this->workspace(WorkspaceRole::Owner, 'Export workspace');
        $event = AnalyticsEvent::query()->create([
            'site_id' => $site->getKey(),
            'event_id' => (string) Str::uuid(),
            'type' => 'event',
            'occurred_at' => now(),
            'received_at' => now(),
            'path' => '/signup',
            'referrer_host' => 'docs.example.test',
            'visitor_hash' => 'pseudonymous-visitor-hash',
            'session_id' => 'pseudonymous-session-id',
            'properties' => ['name' => 'trial-started'],
        ]);
        $batch = IngestionBatch::query()->create([
            'site_id' => $site->getKey(),
            'batch_id' => (string) Str::uuid(),
            'event_count' => 1,
            'status' => 'processed',
            'accepted_at' => now(),
            'processed_at' => now(),
            'failure_message' => 'private ingestion failure detail',
        ]);
        $event->update(['ingestion_batch_id' => $batch->getKey()]);
        $goal = Goal::query()->create([
            'site_id' => $site->getKey(),
            'name' => 'Trial started',
            'kind' => 'event',
            'match_type' => 'exact',
            'match_value' => 'trial-started',
            'active' => true,
        ]);
        $visit = Visit::query()->create([
            'site_id' => $site->getKey(),
            'visit_key' => 'visit-export-1',
            'visitor_hash' => 'pseudonymous-visitor-hash',
            'session_id' => 'pseudonymous-session-id',
            'started_at' => now()->subMinute(),
            'last_seen_at' => now(),
            'landing_path' => '/pricing',
            'exit_path' => '/signup',
            'pageviews' => 2,
            'conversion_count' => 1,
        ]);
        $goal->conversions()->create([
            'site_id' => $site->getKey(),
            'goal_version_id' => $goal->versions()->value('id'),
            'analytics_event_id' => $event->getKey(),
            'visit_id' => $visit->getKey(),
            'converted_at' => now(),
        ]);
        ReportDailyAggregate::query()->create([
            'site_id' => $site->getKey(),
            'local_date' => now()->toDateString(),
            'dimension' => 'page',
            'dimension_value' => '/signup',
            'pageviews' => 2,
            'visits' => 1,
            'visitors' => 1,
            'conversions' => 1,
            'converted_visits' => 1,
            'bounce_eligible' => 1,
            'bounces' => 0,
        ]);
        WorkspaceUsagePeriod::query()->create([
            'workspace_id' => $workspace->getKey(),
            'period_start' => now()->startOfMonth()->toDateString(),
            'accepted_events' => 1,
        ]);
        $reportTokenHash = hash('sha256', 'private-report-token');
        ReportExport::query()->create([
            'workspace_id' => $workspace->getKey(),
            'site_id' => $site->getKey(),
            'requested_by' => $owner->getKey(),
            'token_hash' => $reportTokenHash,
            'status' => 'failed',
            'filters' => ['days' => 30],
            'file_path' => '/private/analytics/report.csv',
            'failure_message' => 'private report storage failure',
            'expires_at' => now()->addHour(),
        ]);
        $invitationTokenHash = hash('sha256', 'private-invitation-token');
        Invitation::query()->create([
            'workspace_id' => $workspace->getKey(),
            'invited_by' => $owner->getKey(),
            'email' => 'pending@example.test',
            'role' => 'viewer',
            'token_hash' => $invitationTokenHash,
            'expires_at' => now()->addDays(3),
        ]);

        [, , $otherSite] = $this->workspace(WorkspaceRole::Owner, 'Other workspace');
        AnalyticsEvent::query()->create([
            'site_id' => $otherSite->getKey(),
            'event_id' => (string) Str::uuid(),
            'type' => 'pageview',
            'occurred_at' => now(),
            'received_at' => now(),
            'path' => '/other-tenant-only',
        ]);

        $site->delete();

        $response = $this->actingAs($owner)->get(route('analytics.workspaces.data.export', $workspace));
        $response->assertOk()
            ->assertHeader('cache-control', 'private, no-store')
            ->assertHeader('content-type', 'application/x-ndjson; charset=UTF-8')
            ->assertHeader('x-content-type-options', 'nosniff');

        $content = $response->streamedContent();
        $records = collect(explode("\n", trim($content)))
            ->map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR));
        $siteRecord = $records->first(fn (array $record): bool => $record['type'] === 'site');
        $eventRecord = $records->first(fn (array $record): bool => $record['type'] === 'event');

        $this->assertSame('buildpusher-analytics-workspace-export', $records->first()['data']['format']);
        $this->assertSame($site->getKey(), $siteRecord['data']['id']);
        $this->assertArrayNotHasKey('verification_token', $siteRecord['data']);
        $this->assertNotNull($siteRecord['data']['deleted_at']);
        $this->assertSame('pseudonymous-visitor-hash', $eventRecord['data']['visitor_hash']);
        $this->assertSame('trial-started', $eventRecord['data']['properties']['name']);
        $this->assertTrue($records->contains(fn (array $record): bool => $record['type'] === 'goal_conversion'));
        $this->assertTrue($records->contains(fn (array $record): bool => $record['type'] === 'report_daily_aggregate'));
        $this->assertTrue($records->contains(fn (array $record): bool => $record['type'] === 'usage_period'));
        $this->assertStringNotContainsString('private ingestion failure detail', $content);
        $this->assertStringNotContainsString($reportTokenHash, $content);
        $this->assertStringNotContainsString($invitationTokenHash, $content);
        $this->assertStringNotContainsString('private-site-verification-token', $content);
        $this->assertStringNotContainsString('/private/analytics/report.csv', $content);
        $this->assertStringNotContainsString('other-tenant-only', $content);
    }

    public function test_workspace_data_screen_and_export_are_limited_to_owners_and_administrators(): void
    {
        [$admin, $workspace] = $this->workspace(WorkspaceRole::Admin, 'Managed workspace');
        $this->actingAs($admin)
            ->get(route('analytics.workspaces.data', $workspace))
            ->assertOk()
            ->assertSee('Download workspace export');

        $viewer = User::factory()->create();
        $workspace->users()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);

        $this->actingAs($viewer)
            ->get(route('analytics.workspaces.data', $workspace))
            ->assertForbidden();
        $this->actingAs($viewer)
            ->get(route('analytics.workspaces.data.export', $workspace))
            ->assertForbidden();
    }

    /** @return array{User, Workspace, Site} */
    private function workspace(WorkspaceRole $role, string $name): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => $name]);
        $workspace->users()->attach($user, ['role' => $role->value]);
        $site = $workspace->sites()->create([
            'name' => $name.' site',
            'public_id' => Str::lower(Str::random(24)),
            'domains' => ['analytics.example.test'],
            'timezone' => 'UTC',
            'verification_token' => 'private-site-verification-token',
        ]);

        return [$user, $workspace, $site];
    }
}
