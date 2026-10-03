<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Contracts\Monitoring\DnsResolver;
use App\Enums\AccountRole;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Webhooks\WebhookSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class WebhooksTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * The project whose account has the endpoints.
     *
     * @var Project
     */
    private Project $project;

    /**
     * The account owner.
     *
     * @var User
     */
    private User $owner;

    /**
     * Set up an account whose owner is signed in to it, with public DNS answers.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->andReturn(['93.184.216.34']);
        $this->project = Project::factory()->create();
        $this->owner = $this->ownerOf($this->project);
        $this->owner->forceFill(['current_account_id' => $this->project->account_id])->save();
    }

    /**
     * Check an endpoint gets only the events it chose, signed with its secret, and that a ping and sending again work.
     *
     * @return void
     */
    /**
     * Endpoints receive the signed events they chose; test events and resends go out on request.
     */
    public function test_endpoints_receive_signed_events_they_chose(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response('', 204)]);
        $this->actingAs($this->owner)->getJson('/api/app/account/webhooks')->assertOk()->assertJsonPath('endpoints', [])->assertSee('deploy.succeeded');
        $this->actingAs($this->owner)->postJson('/api/app/account/webhooks', ['url' => 'http://hooks.example.com/in', 'events' => ['*']])->assertJsonValidationErrors('url');
        $this->actingAs($this->owner)->postJson('/api/app/account/webhooks', ['url' => 'https://hooks.example.com/in', 'events' => ['nope']])->assertJsonValidationErrors('events');
        $added = $this->actingAs($this->owner)->postJson('/api/app/account/webhooks', ['url' => 'https://hooks.example.com/in', 'description' => 'Ops bot', 'events' => ['server.created', 'server.ready']])
            ->assertCreated()->assertJsonPath('redirect', '/account/webhooks');
        $endpoint = WebhookEndpoint::query()->sole();
        $secret = $endpoint->signing_secret;
        $this->assertSame($secret, $added->json('secret'));

        $server = Server::factory()->create(['account_id' => $this->project->account_id, 'name' => 'web-1', 'provisioning_status' => Server::STATUS_ACTIVE]);
        $server->forceFill(['name' => 'web-renamed'])->save();

        $delivery = WebhookDelivery::query()->sole();
        $this->assertSame(['server.created', 'delivered', 1, 204], [$delivery->event, $delivery->status, $delivery->attempts, $delivery->response_status]);
        Http::assertSent(function (Request $request) use ($secret, $delivery): bool {
            $timestamp = $request->header('X-BuildPusher-Timestamp')[0];

            return $request->url() === 'https://hooks.example.com/in' && $request->header('X-BuildPusher-Event')[0] === 'server.created'
                && $request->header('X-BuildPusher-Delivery')[0] === $delivery->id
                && $request->header('X-BuildPusher-Signature')[0] === WebhookSender::signature($timestamp, $request->body(), $secret)
                && $request['data']['server']['name'] === 'web-1' && $request['account_id'] === $this->project->account_id;
        });

        $server->forceFill(['provisioning_status' => Server::STATUS_FAILED])->save();
        $server->forceFill(['provisioning_status' => Server::STATUS_ACTIVE])->save();
        $this->assertSame(['server.created', 'server.ready'], WebhookDelivery::query()->orderBy('id')->pluck('event')->all());

        $this->actingAs($this->owner)->postJson("/api/app/account/webhooks/{$endpoint->id}/send")->assertOk();
        $this->assertSame(1, WebhookDelivery::query()->where('event', 'ping')->where('status', 'delivered')->count());
        $this->actingAs($this->owner)->postJson("/api/app/account/webhooks/{$endpoint->id}/send", ['delivery' => $delivery->id])->assertOk();
        Http::assertSentCount(4);
        $this->actingAs($this->owner)->getJson('/api/app/account/webhooks')->assertOk()->assertJsonPath('endpoints.0.description', 'Ops bot')->assertSee('server.ready')->assertJsonPath('endpoints.0.deliveries.0.status', 'delivered');
    }

    /**
     * Check a failing delivery is tried six times and then counts against the endpoint, which is paused after 20
     * failures in a row, and that turning it back on clears the count; only people who manage the account can.
     *
     * @return void
     */
    /**
     * Failures retry, then pause the endpoint; only those who manage the account reach webhooks.
     */
    public function test_failures_retry_then_pause_the_endpoint_and_access_is_limited(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response('nope', 500)]);
        $this->actingAs($this->owner)->postJson('/api/app/account/webhooks', ['url' => 'https://hooks.example.com/in', 'events' => ['*']])->assertCreated();
        $endpoint = WebhookEndpoint::query()->sole();

        Server::factory()->create(['account_id' => $this->project->account_id]);
        $delivery = WebhookDelivery::query()->sole();
        $this->assertSame(['failed', 6, 500, 'HTTP 500'], [$delivery->status, $delivery->attempts, $delivery->response_status, $delivery->error]);
        $this->assertSame(1, $endpoint->refresh()->failure_count);

        $endpoint->forceFill(['failure_count' => WebhookEndpoint::MAX_FAILURES - 1])->save();
        $this->actingAs($this->owner)->postJson("/api/app/account/webhooks/{$endpoint->id}/send");
        $this->assertFalse($endpoint->refresh()->enabled);
        Server::factory()->create(['account_id' => $this->project->account_id]);
        $this->assertSame(2, WebhookDelivery::query()->count());

        $this->actingAs($this->owner)->putJson("/api/app/account/webhooks/{$endpoint->id}", ['url' => $endpoint->url, 'events' => ['*'], 'enabled' => '1'])->assertOk();
        $this->assertSame([true, 0], [$endpoint->refresh()->enabled, $endpoint->failure_count]);

        $member = User::factory()->create();
        $this->addMember($this->project, $member, AccountRole::Member);
        $member->forceFill(['current_account_id' => $this->project->account_id])->save();
        $this->actingAs($member)->getJson('/api/app/account/webhooks')->assertForbidden();
        $this->actingAs($member)->deleteJson("/api/app/account/webhooks/{$endpoint->id}")->assertForbidden();
        $this->actingAs($this->owner)->deleteJson("/api/app/account/webhooks/{$endpoint->id}")->assertOk();
        $this->assertSame(0, WebhookEndpoint::query()->count());
    }
}
