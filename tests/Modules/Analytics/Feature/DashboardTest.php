<?php

namespace Tests\Modules\Analytics\Feature;

use App\Modules\Analytics\Enums\WorkspaceRole;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Analytics\Models\User;
use App\Modules\Analytics\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

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

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Buildpusher site')
            ->assertSee('Pageviews')
            ->assertSee('/pricing');
    }
}
