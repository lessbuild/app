<?php

declare(strict_types=1);

namespace Tests\Feature\StatusPages;

use App\Models\StatusPage;
use App\Models\StatusSubscription;
use App\Notifications\StatusSubscriptionConfirmation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class StatusSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_visitors_subscribe_confirm_and_unsubscribe(): void
    {
        Notification::fake();
        $page = StatusPage::factory()->create(['slug' => 'acme', 'name' => 'Acme status']);

        $this->postJson('/api/app/status/acme/subscribe', ['email' => ' Fan@Example.com '])->assertSuccessful()->assertJsonPath('message', 'Check your email to confirm status updates.');
        $subscription = StatusSubscription::query()->sole();
        $this->assertSame('fan@example.com', $subscription->email);
        $this->assertSame(hash('sha256', 'fan@example.com'), $subscription->email_hash);
        $this->assertNull($subscription->verified_at);
        $this->assertStringNotContainsString('fan@example.com', (string) $subscription->getRawOriginal('email'));

        $token = null;
        Notification::assertSentOnDemand(StatusSubscriptionConfirmation::class, function (StatusSubscriptionConfirmation $notification, array $channels, AnonymousNotifiable $notifiable) use (&$token): bool {
            $token = $notification->token;

            return $notifiable->routes['mail'] === 'fan@example.com';
        });
        $this->assertIsString($token);
        $this->postJson("/api/app/status/subscriptions/{$subscription->id}/confirm/wrong")->assertNotFound();
        $this->postJson("/api/app/status/subscriptions/{$subscription->id}/confirm/{$token}")->assertJsonRedirect('/api/app/status/acme');
        $this->assertNotNull($subscription->fresh()?->verified_at);
        $this->postJson("/api/app/status/subscriptions/{$subscription->id}/confirm/{$token}")->assertNotFound();

        $unsubscribe = $subscription->unsubscribe_token;
        $this->getJson("/api/app/status/subscriptions/{$subscription->id}/unsubscribe/wrong")->assertNotFound();
        $this->getJson("/api/app/status/subscriptions/{$subscription->id}/unsubscribe/{$unsubscribe}")->assertOk()->assertJsonPath('page.name', 'Acme status');
        $this->assertModelExists($subscription);
        $this->postJson("/api/app/status/subscriptions/{$subscription->id}/unsubscribe/{$unsubscribe}")->assertJsonRedirect('/api/app/status/acme');
        $this->assertModelMissing($subscription);
    }

    public function test_one_click_unsubscribe_needs_no_session(): void
    {
        $subscription = StatusSubscription::factory()->create();

        $this->withMiddleware()->call('POST', "/status/subscriptions/{$subscription->id}/unsubscribe/{$subscription->unsubscribe_token}", ['List-Unsubscribe' => 'One-Click'])->assertSuccessful();

        $this->assertModelMissing($subscription);
    }

    public function test_subscribing_again_restarts_confirmation_and_drafts_refuse_subscribers(): void
    {
        Notification::fake();
        $subscription = StatusSubscription::factory()->create(['email' => 'fan@example.com', 'email_hash' => StatusSubscription::hashEmail('fan@example.com')]);
        $page = $subscription->statusPage;

        $this->postJson("/api/app/status/{$page->slug}/subscribe", ['email' => 'FAN@example.com'])->assertSuccessful();
        $this->assertNull($subscription->fresh()?->verified_at);
        $this->assertDatabaseCount('status_subscriptions', 1);
        $this->postJson("/api/app/status/{$page->slug}/subscribe", ['email' => 'not an email'])->assertJsonValidationErrors('email');

        $draft = StatusPage::factory()->draft()->create();
        $this->postJson("/api/app/status/{$draft->slug}/subscribe", ['email' => 'fan@example.com'])->assertNotFound();
        Notification::assertSentOnDemandTimes(StatusSubscriptionConfirmation::class, 1);
    }
}
