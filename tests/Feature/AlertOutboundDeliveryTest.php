<?php

namespace Tests\Feature;

use App\Modules\Deployer\Jobs\DeliverAlertWebhookJob;
use App\Modules\Deployer\Models\AlertDestination;
use App\Modules\Deployer\Models\AlertOutboundDelivery;
use App\Modules\Deployer\Models\AlertOutboundDeliveryAttempt;
use App\Modules\Deployer\Models\AlertOutboundDeliveryPayload;
use App\Modules\Deployer\Models\ScheduledTask;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\AlertWebhookTargetResolver;
use App\Modules\Deployer\Services\AlertWebhookTransport;
use App\Modules\Deployer\Services\DeliverAlertWebhookDelivery;
use App\Modules\Deployer\Services\QueueAlertWebhookDelivery;
use App\Modules\Deployer\Support\PublicDnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class AlertOutboundDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.enforce_entitlements' => false]);
    }

    public function test_outbox_identity_deduplicates_and_new_queue_messages_only_carry_an_opaque_reference(): void
    {
        Queue::fake();
        [$owner, $destination] = $this->destination();
        $payload = $this->payload();

        $first = app(QueueAlertWebhookDelivery::class)->enqueue($destination, $payload);
        $again = app(QueueAlertWebhookDelivery::class)->enqueue($destination, [...$payload, 'message' => 'replacement']);

        $this->assertSame($first?->id, $again?->id);
        $this->assertSame(1, AlertOutboundDelivery::query()
            ->where('payload_id', $payload['id'])
            ->where('destination_key', $destination->id)
            ->count());
        $this->assertSame('Sensitive incident body.', $first?->payloadRecord?->payload['message']);
        $this->assertStringNotContainsString('Sensitive incident body.', (string) DB::connection('deployer')->table('alert_outbound_delivery_payloads')->value('payload'));
        Queue::assertPushedTimes(DeliverAlertWebhookJob::class, 1);
        $job = Queue::pushed(DeliverAlertWebhookJob::class)->sole();
        $this->assertSame([], $job->payload);
        $this->assertSame($first?->id, $job->deliveryId);
        $this->assertStringNotContainsString('Sensitive incident', serialize($job));
    }

    public function test_webhook_transport_pins_the_single_vetted_dns_answer_and_rejects_any_private_answer(): void
    {
        if (! extension_loaded('curl')) {
            $this->markTestSkipped('The pinned cURL transport requires ext-curl.');
        }
        Http::preventStrayRequests();
        [$owner, $destination] = $this->destination();
        $dns = Mockery::mock(PublicDnsResolver::class);
        $dns->shouldReceive('addresses')->once()->with('hooks.example.com')->andReturn(['8.8.8.8']);
        $this->app->instance(PublicDnsResolver::class, $dns);
        $captured = [];
        Http::fake(function (Request $request, array $options) use (&$captured) {
            $captured = $options;

            return Http::response('', 204);
        });

        $result = app(AlertWebhookTransport::class)->send($destination, $this->payload());
        $this->assertSame('delivered', $result['status']);
        $this->assertSame(['hooks.example.com:443:8.8.8.8'], $captured['curl'][CURLOPT_RESOLVE]);
        $this->assertSame('', $captured['proxy']);
        $this->assertFalse($captured['allow_redirects']);

        Http::fake();
        $mixed = Mockery::mock(PublicDnsResolver::class);
        $mixed->shouldReceive('addresses')->once()->with('hooks.example.com')->andReturn(['8.8.8.8', '127.0.0.1']);
        $resolver = app()->makeWith(AlertWebhookTargetResolver::class, ['dns' => $mixed]);
        $this->assertSame('non_public_address', $resolver->resolve('https://hooks.example.com/alerts')['error']);
        Http::assertNothingSent();

        $offline = Mockery::mock(PublicDnsResolver::class);
        $offline->shouldReceive('addresses')->once()->with('hooks.example.com')->andThrow(new \RuntimeException('private DNS failure text'));
        $this->app->instance(PublicDnsResolver::class, $offline);
        $dnsFailure = app(AlertWebhookTransport::class)->send($destination, $this->payload());
        $this->assertSame('retrying', $dnsFailure['status']);
        $this->assertSame('dns_unavailable', $dnsFailure['error_code']);
        Http::assertNothingSent();
    }

    public function test_bounded_response_and_post_send_transport_failures_become_uncertain_without_replay(): void
    {
        if (! extension_loaded('curl')) {
            $this->markTestSkipped('The pinned cURL transport requires ext-curl.');
        }
        Queue::fake();
        [$owner, $destination] = $this->destination();
        $dns = Mockery::mock(PublicDnsResolver::class);
        $dns->shouldReceive('addresses')->times(3)->with('hooks.example.com')->andReturn(['8.8.8.8']);
        $this->app->instance(PublicDnsResolver::class, $dns);

        $oversized = $this->delivery($destination, $this->payload());
        Http::fake(fn () => Http::response(str_repeat('x', AlertWebhookTransport::MAX_RESPONSE_BYTES + 1), 200));
        app(DeliverAlertWebhookDelivery::class)->process((string) $oversized->id, 0);
        $this->assertSame(AlertOutboundDelivery::STATUS_UNCERTAIN, $oversized->fresh()->status);
        $this->assertSame('response_too_large', $oversized->fresh()->error_code);
        $this->assertStringNotContainsString('Sensitive incident body.', json_encode($oversized->fresh()->getAttributes(), JSON_THROW_ON_ERROR));

        $oversizedHeaders = $this->delivery($destination, [...$this->payload(), 'id' => (string) str()->uuid()]);
        Http::fake(fn () => Http::response('', 200, ['X-Large' => str_repeat('h', AlertWebhookTransport::MAX_RESPONSE_HEADER_BYTES + 1)]));
        app(DeliverAlertWebhookDelivery::class)->process((string) $oversizedHeaders->id, 0);
        $this->assertSame(AlertOutboundDelivery::STATUS_UNCERTAIN, $oversizedHeaders->fresh()->status);
        $this->assertSame('response_too_large', $oversizedHeaders->fresh()->error_code);

        $unknown = $this->delivery($destination, [...$this->payload(), 'id' => (string) str()->uuid()]);
        Http::fake(fn () => throw new ConnectionException('private provider response details'));
        app(DeliverAlertWebhookDelivery::class)->process((string) $unknown->id, 0);
        $this->assertSame(AlertOutboundDelivery::STATUS_UNCERTAIN, $unknown->fresh()->status);
        $this->assertSame('transport_result_unknown', $unknown->fresh()->error_code);
        $this->assertSame(0, $unknown->fresh()->manual_retry_count);
    }

    public function test_expired_sending_lease_and_failed_job_callback_keep_unknown_send_terminal(): void
    {
        Queue::fake();
        [$owner, $destination] = $this->destination();
        $dispatchLost = $this->delivery($destination, $this->payload(), AlertOutboundDelivery::STATUS_QUEUED, [
            'generation' => 2,
            'dispatched_at' => now('UTC')->subMinutes(11),
        ]);
        $outbox = app(QueueAlertWebhookDelivery::class);
        $this->assertSame(1, $outbox->recover());
        Queue::assertPushed(DeliverAlertWebhookJob::class, fn (DeliverAlertWebhookJob $job): bool => $job->deliveryId === $dispatchLost->id
            && $job->generation === 2
            && $job->payload === []);
        $this->assertSame(0, $outbox->recover());
        Queue::assertPushedTimes(DeliverAlertWebhookJob::class, 1);

        $sending = $this->delivery($destination, $this->payload(), AlertOutboundDelivery::STATUS_SENDING, [
            'attempt_count' => 1,
            'cycle_attempts' => 1,
            'processing_token' => (string) str()->uuid(),
            'next_attempt_at' => now('UTC')->subMinute(),
        ]);
        AlertOutboundDeliveryAttempt::query()->create([
            'alert_outbound_delivery_id' => $sending->id,
            'number' => 1,
            'status' => AlertOutboundDelivery::STATUS_SENDING,
            'started_at' => now('UTC')->subMinutes(20),
        ]);

        $outbox->recover();
        $this->assertSame(AlertOutboundDelivery::STATUS_UNCERTAIN, $sending->fresh()->status);
        $this->assertSame(AlertOutboundDelivery::STATUS_UNCERTAIN, $sending->attempts()->sole()->status);
        Queue::assertNotPushed(DeliverAlertWebhookJob::class, fn (DeliverAlertWebhookJob $job): bool => $job->deliveryId === $sending->id);

        $job = new DeliverAlertWebhookJob($destination->id, [], (string) $sending->id, 0);
        $job->failed(new \RuntimeException('provider or queue details are private'));
        $this->assertSame(AlertOutboundDelivery::STATUS_UNCERTAIN, $sending->fresh()->status);
        $this->assertSame('worker_interrupted', $sending->fresh()->error_code);
        $this->actingAs($owner)
            ->post(route('observability.alert-deliveries.retry', $sending), ['confirm' => '1'])
            ->assertStatus(409);
        Queue::assertPushedTimes(DeliverAlertWebhookJob::class, 1);
    }

    public function test_manager_can_retry_one_failed_delivery_and_deleted_destination_keeps_safe_history(): void
    {
        Queue::fake();
        [$owner, $destination] = $this->destination();
        $delivery = $this->delivery($destination, $this->payload(), AlertOutboundDelivery::STATUS_FAILED, [
            'attempt_count' => 1,
            'cycle_attempts' => 1,
        ]);
        AlertOutboundDeliveryAttempt::query()->create([
            'alert_outbound_delivery_id' => $delivery->id,
            'number' => 1,
            'status' => AlertOutboundDelivery::STATUS_FAILED,
            'error_code' => 'provider_rejected',
            'started_at' => now('UTC')->subMinute(),
            'finished_at' => now('UTC'),
        ]);

        $viewer = User::factory()->create();
        $owner->currentOrganization->members()->attach($viewer, ['role' => 'viewer']);
        $viewer->update(['current_organization_id' => $owner->current_organization_id]);
        $this->actingAs($owner)->get(route('observability.index'))->assertSuccessful()->assertSee('Retry once');
        $this->actingAs($viewer)->get(route('observability.index'))->assertSuccessful()->assertDontSee('Retry once');
        $this->actingAs($viewer)
            ->post(route('observability.alert-deliveries.retry', $delivery), ['confirm' => '1'])
            ->assertForbidden();
        $otherWorkspaceOwner = User::factory()->create();
        $this->actingAs($otherWorkspaceOwner)
            ->post(route('observability.alert-deliveries.retry', $delivery), ['confirm' => '1'])
            ->assertForbidden();
        $this->assertSame(AlertOutboundDelivery::STATUS_FAILED, $delivery->fresh()->status);

        $this->actingAs($owner)
            ->post(route('observability.alert-deliveries.retry', $delivery), ['confirm' => '1'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $delivery->refresh();
        $this->assertSame(AlertOutboundDelivery::STATUS_QUEUED, $delivery->status);
        $this->assertSame(1, $delivery->manual_retry_count);
        $job = Queue::pushed(DeliverAlertWebhookJob::class)->sole();
        $this->assertSame([], $job->payload);
        $this->assertSame($delivery->id, $job->deliveryId);

        $this->actingAs($owner)
            ->get(route('observability.index'))
            ->assertSuccessful()
            ->assertSee('Recent alert delivery history')
            ->assertDontSee('Sensitive incident body.')
            ->assertDontSee('https://hooks.example.com/alerts');

        $destination->delete();
        $this->assertNull($delivery->fresh()->alert_destination_id);
        $this->assertSame('webhook', $delivery->fresh()->destination_type);
        $this->assertDatabaseHas('alert_outbound_deliveries', ['id' => $delivery->id]);
        $this->assertDatabaseHas('alert_outbound_delivery_attempts', ['alert_outbound_delivery_id' => $delivery->id, 'number' => 1]);
    }

    public function test_destination_active_state_is_rechecked_before_send_and_retry(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake();
        [$owner, $destination] = $this->destination();
        $delivery = $this->delivery($destination, $this->payload());
        $destination->update(['is_active' => false]);

        app(DeliverAlertWebhookDelivery::class)->process((string) $delivery->id, 0);
        $this->assertSame(AlertOutboundDelivery::STATUS_CANCELLED, $delivery->fresh()->status);
        $this->assertSame('destination_inactive', $delivery->fresh()->error_code);
        Http::assertNothingSent();

        $delivery->forceFill(['status' => AlertOutboundDelivery::STATUS_FAILED])->save();
        $this->actingAs($owner)
            ->post(route('observability.alert-deliveries.retry', $delivery), ['confirm' => '1'])
            ->assertStatus(409);
        $this->assertSame(AlertOutboundDelivery::STATUS_FAILED, $delivery->fresh()->status);
    }

    public function test_expired_active_payloads_fail_before_bounded_pruning_preserves_history(): void
    {
        [$owner, $destination] = $this->destination();
        $delivery = $this->delivery($destination, $this->payload(), AlertOutboundDelivery::STATUS_QUEUED, [
            'retry_available_until' => now('UTC')->subMinute(),
        ]);
        $delivery->payloadRecord->forceFill(['expires_at' => now('UTC')->subMinute()])->save();

        $this->artisan('buildpusher:alert-deliveries:reconcile', ['--limit' => 100])->assertSuccessful();

        $this->assertSame(AlertOutboundDelivery::STATUS_FAILED, $delivery->fresh()->status);
        $this->assertSame('retry_window_expired', $delivery->fresh()->error_code);
        $this->assertDatabaseMissing('alert_outbound_delivery_payloads', ['alert_outbound_delivery_id' => $delivery->id]);
        $this->assertDatabaseHas('alert_outbound_deliveries', ['id' => $delivery->id]);
    }

    public function test_enqueue_attributes_same_workspace_sources_and_never_foreign_ones(): void
    {
        Queue::fake();
        [$owner, $destination] = $this->destination();
        $project = $owner->currentOrganization->projects()->create(['name' => 'Storefront', 'slug' => 'storefront', 'created_by' => $owner->id]);
        $environment = $project->environments()->create(['name' => 'Production', 'slug' => 'production', 'type' => 'production']);
        $task = ScheduledTask::query()->create([
            'environment_id' => $environment->id, 'created_by' => $owner->id, 'name' => 'Nightly',
            'command' => 'php artisan inspire', 'cron_expression' => '0 0 * * *',
        ]);
        $foreign = User::factory()->create();
        $foreignEnvironment = $foreign->currentOrganization->projects()
            ->create(['name' => 'Foreign', 'slug' => 'foreign', 'created_by' => $foreign->id])
            ->environments()->create(['name' => 'Foreign environment', 'slug' => 'foreign', 'type' => 'staging']);
        $foreignTask = ScheduledTask::query()->create([
            'environment_id' => $foreignEnvironment->id, 'created_by' => $foreign->id, 'name' => 'Foreign nightly',
            'command' => 'php artisan inspire', 'cron_expression' => '0 0 * * *',
        ]);

        $own = app(QueueAlertWebhookDelivery::class)->enqueue($destination, [...$this->payload(), 'category' => 'scheduled_task', 'resource_id' => $task->id]);
        $crossWorkspace = app(QueueAlertWebhookDelivery::class)->enqueue($destination, [...$this->payload(), 'category' => 'scheduled_task', 'resource_id' => $foreignTask->id]);
        $missing = app(QueueAlertWebhookDelivery::class)->enqueue($destination, $this->payload());

        $this->assertSame($environment->id, $own?->environment_id);
        $this->assertNull($crossWorkspace?->environment_id);
        $this->assertNull($crossWorkspace?->website_id);
        $this->assertNull($missing?->environment_id);
        $this->assertNull($missing?->website_id);
        Queue::assertPushedTimes(DeliverAlertWebhookJob::class, 3);
    }

    /** @return array{User, AlertDestination} */
    private function destination(): array
    {
        $owner = User::factory()->create();
        $destination = $owner->currentOrganization->alertDestinations()->create([
            'created_by' => $owner->id,
            'name' => 'Sensitive endpoint label',
            'type' => 'webhook',
            'endpoint' => 'https://hooks.example.com/alerts',
            'signing_secret' => 'current-signing-secret',
            'events' => ['failure', 'recovery'],
            'is_active' => true,
        ]);

        return [$owner, $destination];
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'id' => (string) str()->uuid(),
            'event' => 'failure',
            'category' => 'website',
            'resource_id' => 42,
            'title' => 'Sensitive incident title',
            'message' => 'Sensitive incident body.',
        ];
    }

    /** @param array<string, mixed> $payload
     * @param  array<string, mixed>  $overrides
     */
    private function delivery(
        AlertDestination $destination,
        array $payload,
        string $status = AlertOutboundDelivery::STATUS_QUEUED,
        array $overrides = [],
    ): AlertOutboundDelivery {
        $now = now('UTC');
        $delivery = AlertOutboundDelivery::query()->create([
            'id' => (string) str()->uuid(),
            'payload_id' => $payload['id'],
            'destination_key' => $destination->id,
            'organization_id' => $destination->organization_id,
            'alert_destination_id' => $destination->id,
            'destination_type' => $destination->type,
            'event' => $payload['event'],
            'status' => $status,
            'retry_available_until' => $now->copy()->addHours(AlertOutboundDelivery::RETRY_WINDOW_HOURS),
            ...$overrides,
        ]);
        AlertOutboundDeliveryPayload::query()->create([
            'alert_outbound_delivery_id' => $delivery->id,
            'payload' => $payload,
            'expires_at' => $delivery->retry_available_until,
        ]);

        return $delivery;
    }
}
