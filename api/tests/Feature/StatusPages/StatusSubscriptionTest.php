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

        $this->post('/status/acme/subscribe', ['email' => ' Fan@Example.com '])->assertRedirect('/status/acme')->assertSessionHas('status', 'Check your email to confirm status updates.');
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
        $this->get("/status/subscriptions/{$subscription->id}/confirm/wrong")->assertNotFound();
        $this->get("/status/subscriptions/{$subscription->id}/confirm/{$token}")->assertRedirect('/status/acme');
        $this->assertNotNull($subscription->fresh()?->verified_at);
        $this->get("/status/subscriptions/{$subscription->id}/confirm/{$token}")->assertNotFound();

        $unsubscribe = $subscription->unsubscribe_token;
        $this->get("/status/subscriptions/{$subscription->id}/unsubscribe/wrong")->assertNotFound();
        $this->get("/status/subscriptions/{$subscription->id}/unsubscribe/{$unsubscribe}")->assertOk()->assertSee('Stop Acme status emails?');
        $this->assertModelExists($subscription);
        $this->post("/status/subscriptions/{$subscription->id}/unsubscribe/{$unsubscribe}")->assertRedirect('/status/acme');
        $this->assertModelMissing($subscription);
    }

    public function test_one_click_unsubscribe_needs_no_session(): void
    {
        $subscription = StatusSubscription::factory()->create();

        $this->withMiddleware()->call('POST', "/status/subscriptions/{$subscription->id}/unsubscribe/{$subscription->unsubscribe_token}", ['List-Unsubscribe' => 'One-Click'])->assertRedirect();

        $this->assertModelMissing($subscription);
    }

    public function test_subscribing_again_restarts_confirmation_and_drafts_refuse_subscribers(): void
    {
        Notification::fake();
        $subscription = StatusSubscription::factory()->create(['email' => 'fan@example.com', 'email_hash' => StatusSubscription::hashEmail('fan@example.com')]);
        $page = $subscription->statusPage;

        $this->post("/status/{$page->slug}/subscribe", ['email' => 'FAN@example.com'])->assertRedirect();
        $this->assertNull($subscription->fresh()?->verified_at);
        $this->assertDatabaseCount('status_subscriptions', 1);
        $this->post("/status/{$page->slug}/subscribe", ['email' => 'not an email'])->assertSessionHasErrors('email');

        $draft = StatusPage::factory()->draft()->create();
        $this->post("/status/{$draft->slug}/subscribe", ['email' => 'fan@example.com'])->assertNotFound();
        Notification::assertSentOnDemandTimes(StatusSubscriptionConfirmation::class, 1);
    }
}
