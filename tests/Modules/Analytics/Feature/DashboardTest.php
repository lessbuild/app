<?php

namespace Tests\Modules\Analytics\Feature;

use App\Modules\Analytics\Enums\WorkspaceRole;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Analytics\Models\SiteIncidentAnnotation;
use App\Modules\Analytics\Models\SiteReleaseAnnotation;
use App\Modules\Analytics\Models\User;
use App\Modules\Analytics\Models\Workspace;
use Illuminate\Support\Str;
use Tests\Modules\Analytics\RefreshAnalyticsDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshAnalyticsDatabase;

    public function test_authenticated_users_can_view_their_site_overview(): void
    {
        $user = User::factory()->create(['name' => 'Taylor Owner']);
        $workspace = Workspace::create(['name' => "Taylor Owner's workspace"]);
        $workspace->users()->attach($user, ['role' => WorkspaceRole::Owner->value]);
        $site = $workspace->sites()->create([
            'name' => 'Buildpusher site',
            'domains' => ['buildpusher.test'],
            'timezone' => 'UTC',
            'verified_at' => now(),
        ]);

        AnalyticsEvent::create([
            'site_id' => $site->id,
            'event_id' => '00000000-0000-0000-0000-000000000001',
            'type' => 'pageview',
            'occurred_at' => now(),
            'received_at' => now(),
            'path' => '/pricing',
            'visitor_hash' => 'visitor-hash',
        ]);
        SiteReleaseAnnotation::create([
            'site_id' => $site->id,
            'delivery_id' => (string) Str::ulid(),
            'handler' => 'analytics.record-release-annotation.v1',
            'project_connection_id' => (string) Str::ulid(),
            'deployment_id' => '00000000-0000-0000-0000-000000000002',
            'source_build_id' => '82',
            'version' => 'release-2026.09',
            'revision' => str_repeat('a', 40),
            'deployed_at' => now()->subMinute(),
            'payload_hash' => hash('sha256', 'deployment'),
        ]);
        SiteIncidentAnnotation::create([
            'site_id' => $site->id,
            'delivery_id' => (string) Str::ulid(),
            'handler' => 'analytics.record-incident-annotation.v1',
            'project_connection_id' => (string) Str::ulid(),
            'source_incident_id' => '17',
            'status' => 'resolved',
            'occurred_at' => now()->subSeconds(30),
            'payload_hash' => hash('sha256', 'incident'),
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Buildpusher site')
            ->assertSee('Pageviews')
            ->assertSee('/pricing')
            ->assertSee('Recent releases')
            ->assertSee('release-2026.09')
            ->assertSee('Recent Monitor incidents')
            ->assertSee('Incident #17')
            ->assertSee('Resolved')
            ->assertSee('Deployer releases connected to this Analytics site.');
    }

    public function test_breakdown_links_keep_the_selected_site_range_and_filters(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Multi-site workspace']);
        $workspace->users()->attach($user, ['role' => WorkspaceRole::Owner->value]);
        $workspace->sites()->create([
            'name' => 'A first site',
            'domains' => ['first.example'],
            'timezone' => 'UTC',
            'verified_at' => now(),
        ]);
        $selectedSite = $workspace->sites()->create([
            'name' => 'Selected site',
            'domains' => ['selected.example'],
            'timezone' => 'UTC',
            'verified_at' => now(),
        ]);

        AnalyticsEvent::create([
            'site_id' => $selectedSite->id,
            'event_id' => (string) Str::uuid(),
            'type' => 'pageview',
            'occurred_at' => now(),
            'received_at' => now(),
            'path' => '/pricing',
            'utm_source' => 'newsletter',
            'utm_campaign' => 'launch',
            'device_category' => 'Desktop',
            'visitor_hash' => 'selected-visitor',
            'session_id' => 'selected-session',
        ]);

        $response = $this->actingAs($user)->get(route('analytics.dashboard', [
            'site' => $selectedSite->id,
            'days' => 7,
            'source' => 'newsletter',
            'campaign' => 'launch',
            'device' => 'Desktop',
        ]));

        $response->assertOk();
        $expected = route('analytics.dashboard', [
            'site' => $selectedSite->id,
            'days' => 7,
            'path' => '/pricing',
            'source' => 'newsletter',
            'campaign' => 'launch',
            'device' => 'Desktop',
        ]);
        $response->assertSee('href="'.e($expected).'"', false);
    }
}
