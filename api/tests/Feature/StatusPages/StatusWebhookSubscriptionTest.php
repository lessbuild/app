<?php

declare(strict_types=1);

namespace Tests\Feature\StatusPages;

use App\Contracts\Monitoring\DnsResolver;
use App\Models\Project;
use App\Models\StatusPage;
use App\Models\StatusWebhookSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class StatusWebhookSubscriptionTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Check that Slack channels and signed webhooks can follow a status page: they're confirmed first, get each
     * update (webhooks signed), can unsubscribe, and are dropped after repeated failures.
     *
     * @return void
     */
    public function test_slack_and_webhook_subscribers_get_updates(): void
    {
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->andReturn(['93.184.216.34']);
        $project = Project::factory()->withServices(['monitoring'])->create();
        $owner = $this->ownerOf($project);
        $page = StatusPage::factory()->create(['account_id' => $project->account_id, 'name' => 'Acme', 'slug' => 'acme', 'published' => true]);
        $slackUrl = 'https://hooks.slack.com/services/T000/B000/abcdef';
        $hookUrl = 'https://ops.example.com/hooks/status';
        $hookStatus = 200;
        Http::fake([
            'hooks.slack.com/*' => Http::response('ok'),
            'ops.example.com/*' => function () use (&$hookStatus) {
                return Http::response('', $hookStatus);
            },
        ]);

        $this->get('/status/acme')->assertOk()->assertSee('Post updates to Slack or a webhook instead');
        $this->post('/status/acme/subscribe/webhook', ['channel' => 'slack', 'url' => 'https://example.com/not-slack'])->assertSessionHasErrors('url');
        $this->post('/status/acme/subscribe/webhook', ['channel' => 'slack', 'url' => $slackUrl])->assertRedirect('/status/acme');
        $this->post('/status/acme/subscribe/webhook', ['channel' => 'webhook', 'url' => $hookUrl])->assertRedirect('/status/acme')->assertSessionHas('webhook_secret');
        $this->assertSame(2, StatusWebhookSubscription::query()->whereNotNull('verified_at')->count());
        Http::assertSent(fn (Request $request): bool => $request->url() === $slackUrl && str_contains((string) $request['text'], 'Subscribed to Acme status updates'));
        $hook = StatusWebhookSubscription::query()->where('type', 'webhook')->sole();

        $update = ['kind' => 'incident', 'status' => 'investigating', 'severity' => 'major', 'title' => 'Checkout errors', 'message' => 'We’re looking into it.', 'starts_at' => now()->format('Y-m-d\TH:i')];
        $this->actingAs($owner)->post("/projects/{$project->id}/monitoring/status-pages/{$page->id}/updates", $update)->assertRedirect();
        Http::assertSent(fn (Request $request): bool => $request->url() === $slackUrl && str_contains((string) $request['text'], '*Acme: Checkout errors*'));
        Http::assertSent(function (Request $request) use ($hookUrl, $hook): bool {
            if ($request->url() !== $hookUrl || $request['event'] !== 'status_update') {
                return false;
            }
            $expected = 'v1='.hash_hmac('sha256', $request->header('X-BuildPusher-Timestamp')[0].'.'.$request->body(), (string) $hook->signing_secret);

            return $request->header('X-BuildPusher-Signature')[0] === $expected && $request['update']['title'] === 'Checkout errors';
        });

        // Five failures in a row end the webhook subscription.
        $hookStatus = 500;
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($owner)->post("/projects/{$project->id}/monitoring/status-pages/{$page->id}/updates", [...$update, 'title' => "Update {$i}"]);
        }
        $this->assertNull(StatusWebhookSubscription::query()->find($hook->id));

        $slack = StatusWebhookSubscription::query()->sole();
        $link = "/status/webhooks/{$slack->id}/unsubscribe/{$slack->unsubscribe_token}";
        $this->get("/status/webhooks/{$slack->id}/unsubscribe/wrong")->assertNotFound();
        $this->get($link)->assertOk()->assertSee('Stop Acme updates?');
        $this->assertNotNull($slack->fresh());
        $this->post($link)->assertRedirect('/status/acme');
        $this->assertSame(0, StatusWebhookSubscription::query()->count());
    }
}
