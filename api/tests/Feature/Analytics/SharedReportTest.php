<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSite;
use App\Models\AuditEntry;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SharedReportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Check that a site's report can be shared by link, protected by a password, given a new link and unshared, and
     * that only people who manage Analytics can do it.
     *
     * @return void
     */
    public function test_a_report_is_shared_by_link_behind_an_optional_password(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for(Account::factory()->withMember($owner))->withServices(['analytics'])->create();
        $site = AnalyticsSite::factory()->for($project)->create(['name' => 'Shop', 'domains' => ['shop.example']]);
        AnalyticsEvent::create(['site_id' => $site->id, 'event_id' => (string) Str::uuid(), 'type' => 'pageview', 'occurred_at' => now()->subHour(), 'received_at' => now(), 'path' => '/pricing', 'visitor_hash' => 'v']);
        $viewer = User::factory()->create();
        $project->account->memberships()->forceCreate(['user_id' => $viewer->id, 'role' => AccountRole::Viewer]);
        $sitePage = "/api/app/projects/{$project->id}/analytics/sites/{$site->id}";

        $this->actingAs($viewer)->postJson("{$sitePage}/share")->assertForbidden();
        $this->actingAs($owner)->postJson("{$sitePage}/share")->assertJsonRedirect($sitePage);
        $token = (string) $site->refresh()->share_token;
        $this->assertSame(40, strlen($token));
        $this->actingAs($owner)->getJson($sitePage)->assertOk()->assertJsonHasText(route('analytics.shared', $token));
        $this->assertSame(1, AuditEntry::query()->where('action', 'analytics_share.enabled')->count());

        auth()->logout();
        $this->getJson("/api/app/share/analytics/{$token}")->assertOk()->assertJsonHasText('Shop')->assertJsonHasText('/pricing')
            ->assertJsonPath('locked', false)->assertJsonPath('report.period.query', ['days' => 30]);
        $this->getJson('/api/app/share/analytics/'.str_repeat('a', 40))->assertNotFound();
        $this->getJson("/api/app/share/analytics/{$token}?embed=1")->assertOk()->assertJsonHasText('/pricing')->assertJsonPath('embed', true)->assertJsonPath('locked', false);
        $this->actingAs($owner)->getJson($sitePage)->assertJsonPath('sharing.embedUrl', route('analytics.shared.embed', $token));
        auth()->logout();

        $this->actingAs($owner)->postJson("{$sitePage}/share", ['share_password' => 'correct-horse'])->assertSuccessful();
        $this->assertSame($token, $site->refresh()->share_token, 'Adding a password keeps the link.');
        auth()->logout();
        $this->flushSession();
        $this->getJson("/api/app/share/analytics/{$token}")->assertOk()->assertJsonPath('locked', true)->assertJsonLacksText('/pricing');
        $this->postJson("/api/app/share/analytics/{$token}/unlock", ['password' => 'wrong-guess'])->assertJsonValidationErrors('password');
        $this->postJson("/api/app/share/analytics/{$token}/unlock", ['password' => 'correct-horse'])->assertJsonRedirect("/api/app/share/analytics/{$token}");
        $this->getJson("/api/app/share/analytics/{$token}")->assertOk()->assertJsonHasText('/pricing');
        $this->getJson("/api/app/share/analytics/{$token}?embed=1")->assertOk()->assertJsonPath('locked', true)->assertJsonLacksText('/pricing');

        $this->actingAs($owner)->postJson("{$sitePage}/share", ['share_password' => 'another-secret'])->assertSuccessful();
        $this->getJson("/api/app/share/analytics/{$token}")->assertOk()->assertJsonPath('locked', true);

        $this->actingAs($owner)->postJson("{$sitePage}/share", ['new_link' => '1'])->assertSuccessful();
        $this->assertNotSame($token, $newToken = (string) $site->refresh()->share_token);
        $this->getJson("/api/app/share/analytics/{$token}")->assertNotFound();

        $this->actingAs($owner)->deleteJson("{$sitePage}/share")->assertSuccessful();
        $this->getJson("/api/app/share/analytics/{$newToken}")->assertNotFound();
        $this->assertNull($site->refresh()->share_token);
    }
}
