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
        $sitePage = "/projects/{$project->id}/analytics/sites/{$site->id}";

        $this->actingAs($viewer)->post("{$sitePage}/share")->assertForbidden();
        $this->actingAs($owner)->post("{$sitePage}/share")->assertRedirect($sitePage);
        $token = (string) $site->refresh()->share_token;
        $this->assertSame(40, strlen($token));
        $this->actingAs($owner)->get($sitePage)->assertOk()->assertSee(route('analytics.shared', $token));
        $this->assertSame(1, AuditEntry::query()->where('action', 'analytics_share.enabled')->count());

        auth()->logout();
        $this->get("/share/analytics/{$token}")->assertOk()->assertSee('Shop')->assertSee('/pricing')
            ->assertSee('noindex', false)->assertDontSee(__('Manage goals'))->assertSee('share/analytics/'.$token.'?days=30&amp;path=%2Fpricing', false);
        $this->get('/share/analytics/'.str_repeat('a', 40))->assertNotFound();
        $embed = $this->get("/share/analytics/{$token}/embed")->assertOk()->assertSee('/pricing')->assertDontSee(__('Shared report'))
            ->assertSee('share/analytics/'.$token.'/embed?days=30&amp;path=%2Fpricing', false);
        $this->assertFalse($embed->headers->has('X-Frame-Options'));
        $this->assertStringContainsString('frame-ancestors *', (string) $embed->headers->get('Content-Security-Policy'));
        $this->assertSame('DENY', $this->get("/share/analytics/{$token}")->headers->get('X-Frame-Options'), 'Only the embed can be framed.');
        $this->actingAs($owner)->get($sitePage)->assertSee('&lt;iframe src=&quot;'.route('analytics.shared.embed', $token), false);
        auth()->logout();

        $this->actingAs($owner)->post("{$sitePage}/share", ['share_password' => 'correct-horse'])->assertRedirect();
        $this->assertSame($token, $site->refresh()->share_token, 'Adding a password keeps the link.');
        auth()->logout();
        $this->flushSession();
        $this->get("/share/analytics/{$token}")->assertOk()->assertSee(__('This report is protected. Enter the password you were given.'))->assertDontSee('/pricing');
        $this->post("/share/analytics/{$token}/unlock", ['password' => 'wrong-guess'])->assertSessionHasErrors('password');
        $this->post("/share/analytics/{$token}/unlock", ['password' => 'correct-horse'])->assertRedirect("/share/analytics/{$token}");
        $this->get("/share/analytics/{$token}")->assertOk()->assertSee('/pricing');
        $this->get("/share/analytics/{$token}/embed")->assertOk()->assertDontSee('/pricing')->assertSee(__('This report is password-protected, so it can’t be embedded. Share it without a password to embed it.'), false);

        $this->actingAs($owner)->post("{$sitePage}/share", ['share_password' => 'another-secret'])->assertRedirect();
        $this->get("/share/analytics/{$token}")->assertOk()->assertSee(__('This report is protected. Enter the password you were given.'), false);

        $this->actingAs($owner)->post("{$sitePage}/share", ['new_link' => '1'])->assertRedirect();
        $this->assertNotSame($token, $newToken = (string) $site->refresh()->share_token);
        $this->get("/share/analytics/{$token}")->assertNotFound();

        $this->actingAs($owner)->delete("{$sitePage}/share")->assertRedirect();
        $this->get("/share/analytics/{$newToken}")->assertNotFound();
        $this->assertNull($site->refresh()->share_token);
    }
}
