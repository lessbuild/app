<?php

declare(strict_types=1);

namespace Tests\Feature\Alerts;

use App\Actions\Monitoring\SendTestAlert;
use App\Contracts\Monitoring\DnsResolver;
use App\Enums\AccountRole;
use App\Enums\AlertDeliveryStatus;
use App\Models\Account;
use App\Models\AlertDelivery;
use App\Models\AlertDeliveryAttempt;
use App\Models\AlertDestination;
use App\Models\AlertRule;
use App\Models\Incident;
use App\Models\Project;
use App\Models\User;
use App\Services\Monitoring\AlertDeliveryRunner;
use App\Services\Monitoring\AlertDispatcher;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class AlertDeliveryTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    public function test_worker_accepts_once_and_duplicate_job_does_not_send_again(): void
    {
        $this->freezeTime();
        $delivery = AlertDelivery::factory()->create();
        $this->fakeWebhook(204);

        app(AlertDeliveryRunner::class)->process($delivery->id, 0);
        app(AlertDeliveryRunner::class)->process($delivery->id, 0);

        $delivery->refresh();
        $this->assertSame(AlertDeliveryStatus::Accepted, $delivery->status);
        $this->assertTrue($delivery->accepted_at?->equalTo(now()));
        $this->assertSame(1, $delivery->attempt_count);
        $this->assertNull($delivery->processing_token);
        $this->assertNull($delivery->next_attempt_at);
        $this->assertDatabaseHas('alert_delivery_attempts', ['alert_delivery_id' => $delivery->id, 'number' => 1, 'status' => 'accepted', 'http_status' => 204]);
        Http::assertSentCount(1);
    }

    public function test_database_worker_processes_and_removes_its_durable_job(): void
    {
        $destination = AlertDestination::factory()->create();
        $delivery = app(SendTestAlert::class)->handle($destination->account, $this->ownerOf($destination->account), $destination, 0);
        $this->fakeWebhook(204);

        $this->command('queue:work', ['connection' => 'alerts', '--queue' => 'alerts', '--once' => true, '--sleep' => 0, '--tries' => 1, '--timeout' => 45])->assertSuccessful();

        $this->assertSame(AlertDeliveryStatus::Accepted, $this->reload($delivery)->status);
        $this->assertDatabaseEmpty('jobs');
        Http::assertSentCount(1);
    }

    public function test_retry_backoff_preserves_delivery_id_and_event_payload(): void
    {
        $this->freezeTime();
        $delivery = AlertDelivery::factory()->create();
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->andReturn(['1.1.1.1']);
        Http::preventStrayRequests();
        Http::fake(['https://alerts.example.com/events' => Http::sequence()->push('secret response', 503)->push('', 204)]);

        app(AlertDeliveryRunner::class)->process($delivery->id, 0);
        $retry = $this->reload($delivery);
        $this->assertSame(AlertDeliveryStatus::Retrying, $retry->status);
        $this->assertSame(1, $retry->generation);
        $this->assertTrue($retry->next_attempt_at?->equalTo(now()->addSeconds(30)));
        $this->travel(30)->seconds();
        app(AlertDeliveryRunner::class)->process($delivery->id, $retry->generation);

        $this->assertSame(AlertDeliveryStatus::Accepted, $this->reload($delivery)->status);
        $this->assertSame(2, $this->reload($delivery)->attempt_count);
        $this->assertSame(['retrying', 'accepted'], $delivery->attempts()->orderBy('number')->pluck('status')->map(fn ($status) => $status->value)->all());
        $bodies = Http::recorded()->map(fn (array $pair): string => $pair[0]->body())->all();
        $this->assertCount(2, $bodies);
        $this->assertSame($bodies[0], $bodies[1]);
        $this->assertStringNotContainsString('secret response', $this->reload($delivery)->toJson());
    }

    public function test_retries_stop_after_five_attempts(): void
    {
        $this->freezeTime();
        $delivery = AlertDelivery::factory()->create();
        $this->fakeWebhook(503);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $delivery->refresh();
            $this->travelTo($delivery->next_attempt_at);
            app(AlertDeliveryRunner::class)->process($delivery->id, $delivery->generation);
        }

        $delivery->refresh();
        $this->assertSame(AlertDeliveryStatus::Failed, $delivery->status);
        $this->assertSame(5, $delivery->attempt_count);
        $this->assertNull($delivery->next_attempt_at);
        $this->assertNotNull($delivery->failed_at);
        Http::assertSentCount(5);
    }

    #[DataProvider('destinationChanges')]
    public function test_invalidated_destination_cancels_before_network_send(string $change): void
    {
        $delivery = AlertDelivery::factory()->create();
        $destination = $delivery->destination;
        if ($change === 'archive') {
            $destination->delete();
        } else {
            $destination->forceFill($change === 'pause' ? ['enabled' => false] : ['target_revision' => 1])->save();
        }
        Http::preventStrayRequests();

        app(AlertDeliveryRunner::class)->process($delivery->id, 0);

        $this->assertSame(AlertDeliveryStatus::Cancelled, $this->reload($delivery)->status);
        $this->assertSame('destination_changed', $this->reload($delivery)->last_error_code);
        $this->assertDatabaseEmpty('alert_delivery_attempts');
        Http::assertNothingSent();
    }

    /**
     * @return array<array-key, array<int, mixed>>
     */
    public static function destinationChanges(): array
    {
        return ['pause' => ['pause'], 'archive' => ['archive'], 'target revision' => ['revision']];
    }

    public function test_removed_or_unverified_email_recipient_never_receives_queued_notification(): void
    {
        $destination = AlertDestination::factory()->email()->create();
        User::query()->whereKey($destination->recipient_user_id)->update(['email_verified_at' => null]);
        $delivery = AlertDelivery::factory()->for($destination, 'destination')->create();

        app(AlertDeliveryRunner::class)->process($delivery->id, 0);

        $this->assertSame(AlertDeliveryStatus::Cancelled, $this->reload($delivery)->status);
        $this->assertSame('recipient_unavailable', $this->reload($delivery)->last_error_code);
        $this->assertDatabaseEmpty('alert_delivery_attempts');
    }

    public function test_removed_route_cancels_queued_incident_delivery(): void
    {
        [$incident, $destination, $rule] = $this->routed();
        DB::transaction(fn () => app(AlertDispatcher::class)->record($incident, 'opened'));
        $delivery = AlertDelivery::query()->sole();
        $rule->destinations()->detach($destination);
        Http::preventStrayRequests();

        app(AlertDeliveryRunner::class)->process($delivery->id, 0);

        $this->assertSame('route_removed', $this->reload($delivery)->last_error_code);
        Http::assertNothingSent();
    }

    public function test_recovery_waits_until_the_opening_delivery_is_terminal(): void
    {
        $this->freezeTime();
        [$incident] = $this->routed();
        DB::transaction(function () use ($incident): void {
            app(AlertDispatcher::class)->record($incident, 'opened');
            app(AlertDispatcher::class)->record($incident, 'recovered');
        });
        $opening = AlertDelivery::query()->where('event', 'opened')->sole();
        $recovery = AlertDelivery::query()->where('event', 'recovered')->sole();
        $this->fakeWebhook(204);

        app(AlertDeliveryRunner::class)->process($recovery->id, 0);
        $this->assertSame(0, $this->reload($recovery)->attempt_count);
        app(AlertDeliveryRunner::class)->process($opening->id, 0);
        $this->travel(30)->seconds();
        app(AlertDeliveryRunner::class)->process($recovery->id, $this->reload($recovery)->generation);

        $events = Http::recorded()->map(fn (array $pair): string => $pair[0]['event'])->all();
        $this->assertSame(['opened', 'recovered'], $events);
        $this->assertSame(AlertDeliveryStatus::Accepted, $this->reload($recovery)->status);
    }

    public function test_missing_job_recovery_is_bounded_idempotent_and_fences_old_generation(): void
    {
        $this->freezeTime();
        $deliveries = AlertDelivery::factory()->count(3)->create();
        Http::preventStrayRequests();

        $this->assertSame(2, app(AlertDeliveryRunner::class)->recover(2));
        $this->assertDatabaseCount('jobs', 2);
        $this->assertSame(1, app(AlertDeliveryRunner::class)->recover(2));
        $this->assertSame(0, app(AlertDeliveryRunner::class)->recover(2));
        app(AlertDeliveryRunner::class)->process($deliveries->firstOrFail()->id, 0);

        $this->assertDatabaseEmpty('alert_delivery_attempts');
        Http::assertNothingSent();
    }

    #[DataProvider('interruptedChannels')]
    public function test_interrupted_attempts_retry_only_deduplicatable_webhooks(string $type, AlertDeliveryStatus $status): void
    {
        $this->freezeTime();
        $destination = AlertDestination::factory()->create(['type' => $type]);
        $delivery = AlertDelivery::factory()->for($destination, 'destination')->create([
            'status' => 'sending', 'attempt_count' => 1, 'cycle_attempts' => 1, 'processing_token' => fake()->uuid(),
            'next_attempt_at' => now()->subSecond(),
        ]);
        AlertDeliveryAttempt::factory()->for($delivery, 'delivery')->create();

        $this->assertSame(1, app(AlertDeliveryRunner::class)->recover());

        $this->assertSame($status, $this->reload($delivery)->status);
        $this->assertSame('worker_interrupted', $this->reload($delivery)->last_error_code);
        $this->assertNotNull($delivery->attempts()->sole()->finished_at);
    }

    /**
     * @return array<array-key, array<int, mixed>>
     */
    public static function interruptedChannels(): array
    {
        return [
            'webhook' => ['webhook', AlertDeliveryStatus::Retrying],
            'Slack' => ['slack', AlertDeliveryStatus::Uncertain],
            'Discord' => ['discord', AlertDeliveryStatus::Uncertain],
            'email' => ['email', AlertDeliveryStatus::Uncertain],
        ];
    }

    public function test_live_queue_job_and_unexpired_lease_are_not_recovered(): void
    {
        $this->freezeTime();
        $destination = AlertDestination::factory()->create();
        $delivery = app(SendTestAlert::class)->handle($destination->account, $this->ownerOf($destination->account), $destination, 0);
        $sending = AlertDelivery::factory()->create(['status' => 'sending', 'next_attempt_at' => now()->addSeconds(100)]);

        $this->assertSame(0, app(AlertDeliveryRunner::class)->recover());

        $this->assertSame(0, $this->reload($delivery)->generation);
        $this->assertSame(AlertDeliveryStatus::Sending, $this->reload($sending)->status);
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_manual_retry_requires_duplicate_warning_confirmation_and_preserves_attempt_history(): void
    {
        $delivery = AlertDelivery::factory()->create(['status' => 'uncertain', 'attempt_count' => 1, 'cycle_attempts' => 1]);
        AlertDeliveryAttempt::factory()->for($delivery, 'delivery')->create(['status' => 'uncertain', 'finished_at' => now()]);
        $this->actingAs($this->ownerOf($delivery->account));
        $this->post($this->retryUrl($delivery), ['generation' => 0])
            ->assertSessionHasErrors(['confirm' => 'Confirm that you checked the previous attempt and understand a retry may send a duplicate.']);

        $this->post($this->retryUrl($delivery), ['generation' => 0, 'confirm' => 1])->assertRedirect();

        $this->assertSame(AlertDeliveryStatus::Queued, $this->reload($delivery)->status);
        $this->assertSame(1, $this->reload($delivery)->generation);
        $this->assertSame(0, $this->reload($delivery)->cycle_attempts);
        $this->assertSame(1, $this->reload($delivery)->attempt_count);
        $this->assertDatabaseCount('alert_delivery_attempts', 1);
        $this->assertDatabaseCount('jobs', 1);
        $this->post($this->retryUrl($delivery), ['generation' => 0, 'confirm' => 1])->assertConflict();
    }

    public function test_late_worker_result_cannot_overwrite_a_new_generation(): void
    {
        $this->freezeTime();
        $delivery = AlertDelivery::factory()->create();
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->andReturn(['1.1.1.1']);
        Http::preventStrayRequests();
        Http::fake(['https://alerts.example.com/events' => function () use ($delivery) {
            app(AlertDeliveryRunner::class)->interrupted($delivery->id, 0);

            return Http::response('', 204);
        }]);

        app(AlertDeliveryRunner::class)->process($delivery->id, 0);

        $this->assertSame(AlertDeliveryStatus::Retrying, $this->reload($delivery)->status);
        $this->assertSame(1, $this->reload($delivery)->generation);
        $this->assertNull($this->reload($delivery)->accepted_at);
        Http::assertSentCount(1);
    }

    public function test_pause_during_an_inflight_success_does_not_claim_that_send_was_cancelled(): void
    {
        $delivery = AlertDelivery::factory()->create();
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->andReturn(['1.1.1.1']);
        Http::preventStrayRequests();
        Http::fake(['https://alerts.example.com/events' => function () use ($delivery) {
            $delivery->destination->forceFill(['enabled' => false])->save();

            return Http::response('', 204);
        }]);

        app(AlertDeliveryRunner::class)->process($delivery->id, 0);

        $this->assertSame(AlertDeliveryStatus::Accepted, $this->reload($delivery)->status);
        Http::assertSentCount(1);
    }

    public function test_delivery_access_is_scoped_before_validation_and_viewers_cannot_retry(): void
    {
        $delivery = AlertDelivery::factory()->create(['status' => 'failed']);
        $other = Account::factory()->create();
        $this->actingAs($this->ownerOf($other))->post($this->retryUrl($delivery), ['generation' => 0, 'confirm' => 1])->assertNotFound();
        $viewer = User::factory()->create();
        $this->addMember($delivery->account, $viewer, AccountRole::Viewer);

        $this->actingAs($viewer)->post($this->retryUrl($delivery), ['generation' => 0, 'confirm' => 1])->assertForbidden();

        $this->assertDatabaseEmpty('jobs');
    }

    public function test_recovery_command_validates_limit_and_schedule_is_registered(): void
    {
        $this->command('alerts:recover', ['--limit' => 0])->expectsOutput('The limit must be an integer between 1 and 1000.')->assertExitCode(2);
        $this->command('alerts:recover', ['--limit' => 1])->expectsOutput('Recovered 0 alert deliveries.')->assertSuccessful();

        $events = collect(app(Schedule::class)->events())->filter(fn ($event): bool => str_contains($event->command ?? '', 'alerts:recover'));
        $this->assertCount(1, $events);
        $this->assertTrue($events->firstOrFail()->withoutOverlapping);
        $this->assertTrue($events->firstOrFail()->onOneServer);
    }

    public function test_payload_and_processing_secrets_are_not_serialized_or_rendered(): void
    {
        $delivery = AlertDelivery::factory()->create(['processing_token' => fake()->uuid()]);
        $payload = $delivery->payload;
        $payload['private'] = 'private-payload-marker';
        $delivery->forceFill(['payload' => $payload])->save();

        $this->assertArrayNotHasKey('payload', $delivery->toArray());
        $this->assertArrayNotHasKey('processing_token', $delivery->toArray());
        $this->assertArrayNotHasKey('queue_job_uuid', $delivery->toArray());
        $project = Project::factory()->for($delivery->account)->withServices(['monitoring'])->create();
        $this->actingAs($this->ownerOf($delivery->account))->get(route('monitoring.destinations.show', [$project, $delivery->alert_destination_id]))
            ->assertOk()->assertDontSee('private-payload-marker')->assertDontSee((string) $delivery->processing_token);
    }

    private function fakeWebhook(int $status): void
    {
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->andReturn(['1.1.1.1']);
        Http::preventStrayRequests();
        Http::fake(['https://alerts.example.com/events' => Http::response('', $status)]);
    }

    /**
     * @return array{Incident, AlertDestination, AlertRule}
     */
    private function routed(): array
    {
        $rule = AlertRule::factory()->ready()->create();
        $incident = Incident::factory()->for($rule)->create();
        $destination = AlertDestination::factory()->for($rule->environment->project->account)->create();
        $rule->destinations()->attach($destination, ['opened' => true, 'recovered' => true]);

        return [$incident, $destination, $rule];
    }
}
