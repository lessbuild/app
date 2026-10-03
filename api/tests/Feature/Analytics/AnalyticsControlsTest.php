<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Models\Account;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSite;
use App\Models\AnalyticsSiteViewer;
use App\Models\Project;
use App\Models\User;
use App\Notifications\SiteViewerInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AnalyticsControlsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Check ignored addresses aren't counted, #hash pages are kept in hash mode, and view-only people get their own
     * revocable link.
     *
     * @return void
     */
    public function test_ignored_addresses_hash_pages_and_view_only_access(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $project = Project::factory()->for(Account::factory()->withMember($owner))->withServices(['analytics'])->create();
        $site = AnalyticsSite::factory()->for($project)->create(['name' => 'Shop', 'domains' => ['example.com'], 'timezone' => 'UTC', 'verified_at' => now()]);
        $settings = "/projects/{$project->id}/analytics/sites/{$site->id}";
        $this->actingAs($owner)->put($settings, ['name' => 'Shop', 'domains' => 'example.com', 'timezone' => 'UTC', 'excluded_ips' => "203.0.113.0/24\n2001:db8::1"])->assertRedirect();
        $this->assertSame(['203.0.113.0/24', '2001:db8::1'], $site->refresh()->excluded_ips);
        $this->actingAs($owner)->put($settings, ['name' => 'Shop', 'domains' => 'example.com', 'timezone' => 'UTC', 'excluded_ips' => 'office'])->assertSessionHasErrors('excluded_ips');

        $send = fn (string $ip, array $event) => $this->withHeaders(['Origin' => 'https://example.com'])->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson("/api/v1/collect/{$site->public_id}", ['events' => [['id' => (string) Str::uuid(), 'type' => 'pageview', ...$event]]]);
        $send('203.0.113.50', ['path' => '/'])->assertAccepted()->assertJson(['accepted' => 0]);
        $send('198.51.100.1', ['path' => '/#/pricing?ref=x', 'hash' => true])->assertAccepted();
        $send('198.51.100.1', ['path' => '/#/pricing'])->assertAccepted();
        $this->assertSame(['/#/pricing', '/'], AnalyticsEvent::query()->orderBy('id')->pluck('path')->all(), 'Fragments only count in hash mode, and the office isn’t counted.');

        $this->flushHeaders();
        $this->actingAs($owner)->post("{$settings}/viewers", ['email' => 'Client@Example.com'])->assertRedirect($settings);
        $link = null;
        Notification::assertSentTo(new AnonymousNotifiable, SiteViewerInvitation::class, function (SiteViewerInvitation $notification) use (&$link): bool {
            $link = $notification->url;

            return true;
        });
        $this->assertIsString($link);
        $viewer = AnalyticsSiteViewer::query()->sole();
        $this->assertSame('client@example.com', $viewer->email);
        auth()->logout();
        $this->get((string) $link)->assertOk()->assertSee('Shop')->assertSee(__('View-only access'));
        $this->assertNotNull($viewer->refresh()->last_viewed_at);

        $this->actingAs($owner)->delete("{$settings}/viewers/{$viewer->id}")->assertRedirect();
        auth()->logout();
        $this->get((string) $link)->assertNotFound();
    }
}
