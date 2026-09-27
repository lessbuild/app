<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AnalyticsIngestionBatch;
use App\Models\AnalyticsSite;
use App\Models\Domain;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class SitesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->project = Project::factory()->for(Account::factory()->withMember($this->owner))->withServices(['analytics'])->create();
    }

    public function test_analytics_pages_need_the_service_to_be_on(): void
    {
        $other = Project::factory()->for($this->project->account)->create();

        $this->actingAs($this->owner)->get("/projects/{$other->id}/analytics/sites")->assertRedirect(route('projects.services.show', [$other, 'analytics']));
        $this->actingAs($this->owner)->get("/projects/{$this->project->id}/analytics")->assertRedirect(route('analytics.sites', $this->project));
        $this->actingAs($this->owner)->get("/projects/{$this->project->id}/analytics/sites")->assertOk()->assertSee(__('Add your first site'));
    }

    public function test_a_site_is_verified_through_the_projects_verified_domains(): void
    {
        $base = "/projects/{$this->project->id}/analytics/sites";

        $this->actingAs($this->owner)->post($base, ['name' => 'Shop', 'domains' => "https://Shop.Example.com/\nwww.example.com", 'timezone' => 'Europe/London'])->assertRedirect();
        $site = AnalyticsSite::query()->sole();
        $this->assertSame(['shop.example.com', 'www.example.com'], $site->domains);
        $this->assertFalse($site->isVerified());
        $this->actingAs($this->owner)->get("{$base}/{$site->id}")->assertOk()->assertSee('data-site=&quot;'.$site->public_id.'&quot;', false)->assertSee(__('Not verified'))
            ->assertSee('name="domains"', false)->assertSee("shop.example.com\nwww.example.com", false);

        $this->actingAs($this->owner)->post("{$base}/{$site->id}/verify")->assertSessionHas('notice');
        (new Domain)->forceFill(['project_id' => $this->project->id, 'hostname' => 'example.com', 'verification_token' => 'x', 'verified_at' => now()])->save();
        $this->actingAs($this->owner)->post("{$base}/{$site->id}/verify")->assertSessionHas('status');
        $this->assertTrue($site->refresh()->isCollectionAvailable(), 'shop.example.com is a subdomain of the verified example.com.');

        $this->actingAs($this->owner)->put("{$base}/{$site->id}", ['name' => 'Shop', 'domains' => 'shop.example.com', 'timezone' => 'UTC', 'excluded_paths' => "/admin/*\n/preview"])->assertRedirect();
        $this->assertSame(['/admin/*', '/preview'], $site->refresh()->excluded_paths);
        $this->assertTrue($site->excludesPath('/admin/users'));
    }

    public function test_sites_with_a_verified_hostname_are_verified_straight_away_and_bad_hostnames_are_refused(): void
    {
        (new Domain)->forceFill(['project_id' => $this->project->id, 'hostname' => 'example.com', 'verification_token' => 'x', 'verified_at' => now()])->save();
        $base = "/projects/{$this->project->id}/analytics/sites";

        $this->actingAs($this->owner)->post($base, ['name' => 'Bad', 'domains' => 'localhost', 'timezone' => 'UTC'])->assertSessionHasErrors('domains');
        $this->actingAs($this->owner)->post($base, ['name' => 'Site', 'domains' => 'example.com', 'timezone' => 'UTC'])->assertRedirect();
        $this->assertTrue(AnalyticsSite::query()->sole()->isVerified());
    }

    public function test_viewers_see_sites_but_cannot_change_them_and_other_projects_sites_are_not_found(): void
    {
        $site = AnalyticsSite::factory()->for($this->project)->create();
        $viewer = User::factory()->create();
        $this->project->account->memberships()->forceCreate(['user_id' => $viewer->id, 'role' => AccountRole::Viewer]);
        $base = "/projects/{$this->project->id}/analytics/sites";

        $this->actingAs($viewer)->get("{$base}/{$site->id}")->assertOk()->assertDontSee(__('Delete :site', ['site' => $site->name]));
        $this->actingAs($viewer)->put("{$base}/{$site->id}", ['name' => 'x', 'domains' => 'example.com', 'timezone' => 'UTC'])->assertForbidden();

        $elsewhere = AnalyticsSite::factory()->create();
        $this->actingAs($this->owner)->get("{$base}/{$elsewhere->id}")->assertNotFound();

        $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => PHP_INT_MAX])->delete("{$base}/{$site->id}")->assertRedirect("{$base}");
        $this->assertNull($site->fresh());
    }

    public function test_the_tracker_script_is_served_and_pending_batches_are_redispatched(): void
    {
        $this->assertFileExists(public_path('tracker/v1.js'));
        $this->assertStringContainsString("'/api/v1/collect/'", (string) file_get_contents(public_path('tracker/v1.js')));

        Queue::fake();
        $site = AnalyticsSite::factory()->for($this->project)->create();
        (new AnalyticsIngestionBatch)->forceFill(['site_id' => $site->id, 'batch_id' => '8f5c9b8e-7f4e-4a57-9c2b-1a2b3c4d5e6f', 'status' => 'pending', 'accepted_at' => now()->subMinute()])->save();

        Artisan::call('analytics:dispatch-pending');
        Queue::assertPushed(\App\Jobs\Analytics\ProcessEventBatch::class, 1);
        $this->assertSame(0, Artisan::call('analytics:prune'));
    }
}
