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

        $this->actingAs($this->owner)->getJson("/api/app/projects/{$other->id}/analytics/sites")->assertStatus(409)->assertJsonPath('redirect', route('projects.services.show', [$other, 'analytics'], false));
        $this->actingAs($this->owner)->getJson("/api/app/projects/{$this->project->id}/analytics")->assertOk()->assertJsonPath('site', null)->assertJsonPath('report', null);
        $this->actingAs($this->owner)->getJson("/api/app/projects/{$this->project->id}/analytics/sites")->assertOk()->assertJsonCount(0, 'sites');
    }

    public function test_a_site_is_verified_through_the_projects_verified_domains(): void
    {
        $base = "/api/app/projects/{$this->project->id}/analytics/sites";

        $this->actingAs($this->owner)->postJson($base, ['name' => 'Shop', 'domains' => "https://Shop.Example.com/\nwww.example.com", 'timezone' => 'Europe/London'])->assertSuccessful();
        $site = AnalyticsSite::query()->sole();
        $this->assertSame(['shop.example.com', 'www.example.com'], $site->domains);
        $this->assertFalse($site->isVerified());
        $this->actingAs($this->owner)->getJson("{$base}/{$site->id}")->assertOk()->assertJsonPath('site.publicId', $site->public_id)->assertJsonPath('site.verified', false)
            ->assertJsonPath('site.domains', ['shop.example.com', 'www.example.com']);

        $this->actingAs($this->owner)->postJson("{$base}/{$site->id}/verify")->assertSuccessful()->assertJsonPath('warning', fn ($warning): bool => is_string($warning));
        (new Domain)->forceFill(['project_id' => $this->project->id, 'hostname' => 'example.com', 'verification_token' => 'x', 'verified_at' => now()])->save();
        $this->actingAs($this->owner)->postJson("{$base}/{$site->id}/verify")->assertSuccessful()->assertJsonPath('message', fn ($message): bool => is_string($message));
        $this->assertTrue($site->refresh()->isCollectionAvailable(), 'shop.example.com is a subdomain of the verified example.com.');

        $this->actingAs($this->owner)->putJson("{$base}/{$site->id}", ['name' => 'Shop', 'domains' => 'shop.example.com', 'timezone' => 'UTC', 'excluded_paths' => "/admin/*\n/preview"])->assertSuccessful();
        $this->assertSame(['/admin/*', '/preview'], $site->refresh()->excluded_paths);
        $this->assertTrue($site->excludesPath('/admin/users'));
        $this->actingAs($this->owner)->putJson("{$base}/{$site->id}", ['name' => 'Shop', 'domains' => 'shop.example.com', 'timezone' => 'UTC', 'custom_properties' => 'plan, author, plan'])->assertSuccessful();
        $this->assertSame(['plan', 'author'], $site->refresh()->custom_properties);
    }

    public function test_sites_with_a_verified_hostname_are_verified_straight_away_and_bad_hostnames_are_refused(): void
    {
        (new Domain)->forceFill(['project_id' => $this->project->id, 'hostname' => 'example.com', 'verification_token' => 'x', 'verified_at' => now()])->save();
        $base = "/api/app/projects/{$this->project->id}/analytics/sites";

        $this->actingAs($this->owner)->postJson($base, ['name' => 'Bad', 'domains' => 'localhost', 'timezone' => 'UTC'])->assertJsonValidationErrors('domains');
        $this->actingAs($this->owner)->postJson($base, ['name' => 'Site', 'domains' => 'example.com', 'timezone' => 'UTC'])->assertSuccessful();
        $this->assertTrue(AnalyticsSite::query()->sole()->isVerified());
    }

    public function test_viewers_see_sites_but_cannot_change_them_and_other_projects_sites_are_not_found(): void
    {
        $site = AnalyticsSite::factory()->for($this->project)->create();
        $viewer = User::factory()->create();
        $this->project->account->memberships()->forceCreate(['user_id' => $viewer->id, 'role' => AccountRole::Viewer]);
        $base = "/api/app/projects/{$this->project->id}/analytics/sites";

        $this->actingAs($viewer)->getJson("{$base}/{$site->id}")->assertOk()->assertJsonPath('canManage', false);
        $this->actingAs($viewer)->putJson("{$base}/{$site->id}", ['name' => 'x', 'domains' => 'example.com', 'timezone' => 'UTC'])->assertForbidden();

        $elsewhere = AnalyticsSite::factory()->create();
        $this->actingAs($this->owner)->getJson("{$base}/{$elsewhere->id}")->assertNotFound();

        $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => PHP_INT_MAX])->deleteJson("{$base}/{$site->id}")->assertJsonRedirect("{$base}");
        $this->assertNull($site->fresh());
    }

    public function test_the_tracker_script_is_served_and_pending_batches_are_redispatched(): void
    {
        $this->assertFileExists(public_path('tracker/v1.js'));
        $this->assertStringContainsString('/api/v1/collect/', (string) file_get_contents(public_path('tracker/v1.js')));
        $this->assertFileExists(public_path('tracker/v1-extras.js'));
        $this->assertLessThan(8000, filesize(public_path('tracker/v1.js')), 'The tracker stays small.');

        Queue::fake();
        $site = AnalyticsSite::factory()->for($this->project)->create();
        (new AnalyticsIngestionBatch)->forceFill(['site_id' => $site->id, 'batch_id' => '8f5c9b8e-7f4e-4a57-9c2b-1a2b3c4d5e6f', 'status' => 'pending', 'accepted_at' => now()->subMinute()])->save();

        Artisan::call('analytics:dispatch-pending');
        Queue::assertPushed(\App\Jobs\Analytics\ProcessEventBatch::class, 1);
        $this->assertSame(0, Artisan::call('analytics:prune'));
    }
}
