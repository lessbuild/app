<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\PlatformStatusIncident;
use App\Models\PlatformStatusSubscriber;
use App\Notifications\PlatformStatusChanged;
use App\Notifications\PlatformStatusSubscriptionConfirmation;
use App\Services\Admin\PlatformStatusHistory;
use App\Services\Admin\SystemHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class PlatformStatusHistoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Check that each check adds to the day's tally, that the status page shows 90 days and the uptime, that two failed
     * checks in a row open an incident (emailing confirmed subscribers), and that a passing check resolves it.
     *
     * @return void
     */
    public function test_checks_build_the_history_and_open_and_resolve_incidents(): void
    {
        Notification::fake();
        $subscriber = new PlatformStatusSubscriber;
        $subscriber->forceFill(['email' => 'ops@example.com', 'email_hash' => PlatformStatusSubscriber::hashEmail('ops@example.com'), 'unsubscribe_token' => 'unsubscribe-token', 'verified_at' => now()])->save();
        $history = app(PlatformStatusHistory::class);

        Cache::forever(SystemHealth::HEARTBEAT_KEY, now()->getTimestamp());
        $history->record();
        Cache::forget(SystemHealth::HEARTBEAT_KEY);
        $history->record();
        $this->assertSame(0, PlatformStatusIncident::query()->count(), 'One failed check doesn’t open an incident.');
        $history->record();
        $this->assertTrue(PlatformStatusIncident::query()->where('component', 'background')->whereNull('resolved_at')->exists());
        Notification::assertSentTo(new AnonymousNotifiable, PlatformStatusChanged::class, fn (PlatformStatusChanged $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'ops@example.com');

        Cache::forever(SystemHealth::HEARTBEAT_KEY, now()->getTimestamp());
        $history->record();
        $this->assertFalse(PlatformStatusIncident::query()->whereNull('resolved_at')->exists());

        Cache::forget('platform:status');
        $page = $this->getJson('/api/app/platform-status')->assertOk()->assertJsonPath('historyDays', 90);
        $background = collect($page->json('components'))->firstWhere('key', 'background');
        $this->assertCount(90, $background['days']);
        $this->assertSame('down', $background['days'][89]);
        $this->assertEqualsWithDelta(50.0, $background['uptime'], 0.01);
        $this->assertNotNull($page->json('incidents.0.resolvedAt'));
    }

    /**
     * Check that subscribing sends a confirmation (and answers the same for an address already subscribed), that the
     * link confirms it, and that the unsubscribe link removes it.
     *
     * @return void
     */
    public function test_people_can_subscribe_confirm_and_unsubscribe(): void
    {
        Notification::fake();

        $this->postJson('/api/app/platform-status/subscribe', ['email' => 'Someone@Example.com'])->assertOk()->assertJsonPath('message', 'Check your inbox to confirm your address.');
        $token = null;
        Notification::assertSentTo(new AnonymousNotifiable, PlatformStatusSubscriptionConfirmation::class, function (PlatformStatusSubscriptionConfirmation $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });
        $subscriber = PlatformStatusSubscriber::query()->sole();
        $this->assertSame('someone@example.com', $subscriber->email);
        $this->assertNull($subscriber->verified_at);

        $this->postJson("/api/app/platform-status/subscribers/{$subscriber->id}/confirm/wrong")->assertNotFound();
        $this->postJson("/api/app/platform-status/subscribers/{$subscriber->id}/confirm/{$token}")->assertOk()->assertJsonPath('redirect', '/status');
        $this->assertNotNull($subscriber->refresh()->verified_at);

        $this->postJson('/api/app/platform-status/subscribe', ['email' => 'someone@example.com'])->assertOk()->assertJsonPath('message', 'Check your inbox to confirm your address.');
        Notification::assertSentTimes(PlatformStatusSubscriptionConfirmation::class, 1);

        $this->postJson("/api/app/platform-status/subscribers/{$subscriber->id}/unsubscribe/wrong")->assertNotFound();
        $this->postJson("/api/app/platform-status/subscribers/{$subscriber->id}/unsubscribe/{$subscriber->unsubscribe_token}")->assertOk();
        $this->assertSame(0, PlatformStatusSubscriber::query()->count());
    }
}
