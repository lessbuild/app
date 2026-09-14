<?php

namespace Tests\Feature;

use App\Actions\Server\AcknowledgeServerTroubleshootingOutputFramesAction;
use App\Actions\Server\ClaimServerTroubleshootingBrokerLeaseAction;
use App\Actions\Server\CloseServerTroubleshootingSessionAction;
use App\Actions\Server\ExpireServerTroubleshootingSessionsAction;
use App\Actions\Server\OpenServerTroubleshootingSessionAction;
use App\Actions\Server\PruneServerTroubleshootingFramesAction;
use App\Actions\Server\QueueServerTroubleshootingInputFrameAction;
use App\Actions\Server\ReadServerTroubleshootingOutputFramesAction;
use App\Actions\Server\ReleaseServerTroubleshootingBrokerLeaseAction;
use App\Actions\Server\RenewServerTroubleshootingBrokerLeaseAction;
use App\Contracts\ServerTroubleshootingConnection;
use App\Contracts\ServerTroubleshootingTransport;
use App\Data\ServerTroubleshootingBrokerLease;
use App\Data\ServerTroubleshootingTerminalSize;
use App\Enums\ServerTroubleshootingBrokerOutcome;
use App\Enums\ServerTroubleshootingFrameDirection;
use App\Models\Provider;
use App\Models\Server;
use App\Models\ServerTroubleshootingFrame;
use App\Models\ServerTroubleshootingSession;
use App\Models\User;
use App\Services\ServerTroubleshootingBroker;
use App\Services\ServerTroubleshootingFrameStore;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ServerTroubleshootingBrokerTest extends TestCase
{
    use RefreshDatabase;

    public function test_input_is_encrypted_sequenced_and_not_serialized_as_a_transcript(): void
    {
        [$owner, $server] = $this->resources();
        $grant = app(OpenServerTroubleshootingSessionAction::class)->handle($server, $owner);

        $frame = app(QueueServerTroubleshootingInputFrameAction::class)->handle(
            $grant->session,
            $owner,
            $grant->token,
            "echo sensitive-input\n",
        );

        $stored = ServerTroubleshootingFrame::query()->findOrFail($frame->id);
        $ciphertext = DB::table('server_troubleshooting_frames')->whereKey($frame->id)->value('payload');

        $this->assertSame(ServerTroubleshootingFrameDirection::Input, $frame->direction);
        $this->assertSame(1, $frame->sequence);
        $this->assertSame(strlen("echo sensitive-input\n"), $frame->bytes);
        $this->assertSame("echo sensitive-input\n", $frame->payload);
        $this->assertNotSame("echo sensitive-input\n", $ciphertext);
        $this->assertArrayNotHasKey('payload', $stored->toArray());
        $this->assertArrayNotHasKey('grant_hash', $grant->session->fresh()->toArray());
    }

    public function test_unauthorized_actor_is_rejected_before_malformed_input_and_no_frame_is_written(): void
    {
        [$owner, $server] = $this->resources();
        $grant = app(OpenServerTroubleshootingSessionAction::class)->handle($server, $owner);
        $intruder = User::factory()->create();

        $this->expectException(AuthorizationException::class);
        app(QueueServerTroubleshootingInputFrameAction::class)->handle(
            $grant->session,
            $intruder,
            $grant->token,
            str_repeat('x', 9000),
        );

        $this->assertDatabaseCount('server_troubleshooting_frames', 0);
    }

    public function test_input_buffer_is_bounded_without_partial_persistence(): void
    {
        config()->set('lessbuild.troubleshooting.max_pending_input_frames', 1);
        [$owner, $server] = $this->resources();
        $grant = app(OpenServerTroubleshootingSessionAction::class)->handle($server, $owner);
        $action = app(QueueServerTroubleshootingInputFrameAction::class);

        $action->handle($grant->session, $owner, $grant->token, 'first');

        try {
            $action->handle($grant->session, $owner, $grant->token, 'second');
            $this->fail('The second pending input frame should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'The troubleshooting input buffer is full; wait for the session to send pending input.',
                $exception->errors()['input'][0],
            );
        }

        $this->assertDatabaseCount('server_troubleshooting_frames', 1);
    }

    public function test_output_is_read_in_sequence_and_acknowledged_without_leaking_ciphertext(): void
    {
        [$owner, $server] = $this->resources();
        $grant = app(OpenServerTroubleshootingSessionAction::class)->handle($server, $owner);
        $lease = $this->claim($grant->session, 1201);
        $store = app(ServerTroubleshootingFrameStore::class);

        $this->assertSame(1, $store->appendOutput($lease, "first-output\n"));
        $this->assertSame(1, $store->appendOutput($lease, "second-output\n"));
        $frames = app(ReadServerTroubleshootingOutputFramesAction::class)->handle(
            $grant->session,
            $owner,
            $grant->token,
            0,
            1,
        );

        $this->assertCount(1, $frames);
        $this->assertSame(ServerTroubleshootingFrameDirection::Output, $frames[0]->direction);
        $this->assertSame(1, $frames[0]->sequence);
        $this->assertSame("first-output\n", $frames[0]->payload);
        $this->assertNotSame(
            "first-output\n",
            DB::table('server_troubleshooting_frames')->whereKey($frames[0]->id)->value('payload'),
        );

        $this->assertSame(
            1,
            app(AcknowledgeServerTroubleshootingOutputFramesAction::class)->handle(
                $grant->session,
                $owner,
                $grant->token,
                1,
            ),
        );
        $remaining = app(ReadServerTroubleshootingOutputFramesAction::class)->handle(
            $grant->session,
            $owner,
            $grant->token,
            1,
            10,
        );

        $this->assertCount(1, $remaining);
        $this->assertSame(2, $remaining[0]->sequence);
    }

    public function test_lease_attempt_and_process_identity_reject_stale_brokers(): void
    {
        [$owner, $server] = $this->resources();
        $grant = app(OpenServerTroubleshootingSessionAction::class)->handle($server, $owner);
        $claim = app(ClaimServerTroubleshootingBrokerLeaseAction::class);
        $first = $claim->handle($grant->session, 1201);

        $this->assertInstanceOf(ServerTroubleshootingBrokerLease::class, $first);
        $this->assertNull($claim->handle($grant->session, 1202));
        $this->assertFalse(app(ReleaseServerTroubleshootingBrokerLeaseAction::class)->handle(
            new ServerTroubleshootingBrokerLease($first->session, $first->token, $first->attempt, 9999),
        ));
        $this->assertSame(1201, $grant->session->fresh()->broker_process_id);

        $this->assertTrue(app(ReleaseServerTroubleshootingBrokerLeaseAction::class)->handle($first));
        $second = $claim->handle($grant->session->fresh(), 1202);

        $this->assertNotNull($second);
        $this->assertSame(2, $second->attempt);
        $this->assertFalse(app(RenewServerTroubleshootingBrokerLeaseAction::class)->handle($first));
        $this->assertTrue(app(RenewServerTroubleshootingBrokerLeaseAction::class)->handle($second));
    }

    public function test_broker_renewal_revokes_the_session_when_membership_is_removed(): void
    {
        [$owner, $server] = $this->resources();
        $operator = $this->member($owner, 'operator');
        $grant = app(OpenServerTroubleshootingSessionAction::class)->handle($server, $operator);
        $lease = $this->claim($grant->session, 1201);

        $owner->currentOrganization->members()->detach($operator);

        $this->assertFalse(app(RenewServerTroubleshootingBrokerLeaseAction::class)->handle($lease));

        $updated = $grant->session->fresh();
        $this->assertSame(ServerTroubleshootingSession::STATUS_REVOKED, $updated->status);
        $this->assertSame(ServerTroubleshootingSession::CLOSE_REASON_REVOKED, $updated->close_reason);
        $this->assertNull($updated->broker_lease_hash);
        $this->assertNull($updated->broker_process_id);
    }

    public function test_execute_role_downgrade_keeps_connectivity_but_denies_new_shell_input(): void
    {
        [$owner, $server] = $this->resources();
        $operator = $this->member($owner, 'operator');
        $grant = app(OpenServerTroubleshootingSessionAction::class)->handle($server, $operator);
        $lease = $this->claim($grant->session, 1201);

        $owner->currentOrganization->members()->updateExistingPivot($operator->id, ['role' => 'viewer']);

        $this->assertTrue(app(RenewServerTroubleshootingBrokerLeaseAction::class)->handle($lease));
        $this->assertSame(ServerTroubleshootingSession::STATUS_CONNECTING, $grant->session->fresh()->status);

        $this->expectException(AuthorizationException::class);
        app(QueueServerTroubleshootingInputFrameAction::class)->handle(
            $grant->session,
            $operator,
            $grant->token,
            "whoami\n",
        );
    }

    public function test_expired_broker_lease_fails_closed_instead_of_starting_a_second_process(): void
    {
        [$owner, $server] = $this->resources();
        $grant = app(OpenServerTroubleshootingSessionAction::class)->handle($server, $owner);
        $lease = $this->claim($grant->session, 1201);
        $grant->session->update(['broker_lease_expires_at' => now()->subSecond()]);

        $this->assertNull(app(ClaimServerTroubleshootingBrokerLeaseAction::class)->handle($grant->session, 1202));
        $this->assertFalse(app(RenewServerTroubleshootingBrokerLeaseAction::class)->handle($lease));
        $updated = $grant->session->fresh();
        $this->assertSame(ServerTroubleshootingSession::STATUS_FAILED, $updated->status);
        $this->assertSame(ServerTroubleshootingSession::CLOSE_REASON_TRANSPORT, $updated->close_reason);
        $this->assertNull($updated->broker_lease_hash);
    }

    public function test_maintenance_terminalizes_an_expired_broker_lease_without_contacting_the_server(): void
    {
        [$owner, $server] = $this->resources();
        $grant = app(OpenServerTroubleshootingSessionAction::class)->handle($server, $owner);
        $this->claim($grant->session, 1201);
        $grant->session->update(['broker_lease_expires_at' => now()->subSecond()]);

        $this->assertSame(1, app(ExpireServerTroubleshootingSessionsAction::class)->handle(1));
        $updated = $grant->session->fresh();
        $this->assertSame(ServerTroubleshootingSession::STATUS_FAILED, $updated->status);
        $this->assertSame(ServerTroubleshootingSession::CLOSE_REASON_TRANSPORT, $updated->close_reason);
        $this->assertNull($updated->broker_lease_hash);
    }

    public function test_user_close_clears_broker_ownership_before_the_broker_releases(): void
    {
        [$owner, $server] = $this->resources();
        $grant = app(OpenServerTroubleshootingSessionAction::class)->handle($server, $owner);
        $this->claim($grant->session, 1201);

        $this->assertTrue(app(CloseServerTroubleshootingSessionAction::class)->handle(
            $grant->session,
            $owner,
            $grant->token,
        ));
        $updated = $grant->session->fresh();
        $this->assertSame(ServerTroubleshootingSession::STATUS_CLOSED, $updated->status);
        $this->assertNull($updated->broker_lease_hash);
        $this->assertNull($updated->broker_process_id);
    }

    public function test_broker_writes_at_most_once_input_and_persists_bounded_output(): void
    {
        [$owner, $server] = $this->resources();
        $grant = app(OpenServerTroubleshootingSessionAction::class)->handle($server, $owner);
        app(QueueServerTroubleshootingInputFrameAction::class)->handle($grant->session, $owner, $grant->token, "whoami\n");
        $connection = new FakeServerTroubleshootingConnection("root\n");
        $this->app->instance(ServerTroubleshootingTransport::class, new FakeServerTroubleshootingTransport($connection));

        $result = app(ServerTroubleshootingBroker::class)->run(
            $grant->session,
            1201,
            new ServerTroubleshootingTerminalSize,
            1,
        );

        $this->assertSame(ServerTroubleshootingBrokerOutcome::Released, $result->outcome);
        $this->assertSame(1, $result->cycles);
        $this->assertSame(["whoami\n"], $connection->writes);
        $this->assertTrue($connection->closed);
        $this->assertSame(ServerTroubleshootingSession::STATUS_CONNECTED, $grant->session->fresh()->status);
        $this->assertNull($grant->session->fresh()->broker_lease_hash);
        $this->assertDatabaseHas('server_troubleshooting_frames', [
            'direction' => ServerTroubleshootingFrameDirection::Input->value,
        ]);
        $this->assertDatabaseHas('server_troubleshooting_frames', [
            'direction' => ServerTroubleshootingFrameDirection::Output->value,
            'payload_bytes' => 5,
        ]);
        $this->assertSame(1, ServerTroubleshootingFrame::query()->where('direction', 'output')->count());
    }

    public function test_broker_closes_and_marks_failed_when_durable_output_would_exceed_the_buffer(): void
    {
        config()->set('lessbuild.troubleshooting.max_pending_output_bytes', 4);
        [$owner, $server] = $this->resources();
        $grant = app(OpenServerTroubleshootingSessionAction::class)->handle($server, $owner);
        $connection = new FakeServerTroubleshootingConnection('12345');
        $this->app->instance(ServerTroubleshootingTransport::class, new FakeServerTroubleshootingTransport($connection));

        $result = app(ServerTroubleshootingBroker::class)->run(
            $grant->session,
            1201,
            new ServerTroubleshootingTerminalSize,
        );

        $this->assertSame(ServerTroubleshootingBrokerOutcome::Failed, $result->outcome);
        $this->assertTrue($connection->closed);
        $this->assertSame(ServerTroubleshootingSession::STATUS_FAILED, $grant->session->fresh()->status);
        $this->assertDatabaseCount('server_troubleshooting_frames', 0);
    }

    public function test_broker_command_runs_a_bounded_window_and_returns_a_safe_status(): void
    {
        [$owner, $server] = $this->resources();
        $grant = app(OpenServerTroubleshootingSessionAction::class)->handle($server, $owner);
        $connection = new FakeServerTroubleshootingConnection;
        $this->app->instance(ServerTroubleshootingTransport::class, new FakeServerTroubleshootingTransport($connection));

        $this->artisan('buildpusher:troubleshooting:broker', [
            'session' => $grant->session->public_id,
            '--cycles' => 1,
        ])
            ->expectsOutput('Troubleshooting broker ended with released after 1 cycle(s).')
            ->assertExitCode(0);

        $this->assertSame(ServerTroubleshootingSession::STATUS_CONNECTED, $grant->session->fresh()->status);
    }

    public function test_expired_frames_are_pruned_in_a_bounded_batch(): void
    {
        config()->set('lessbuild.troubleshooting.frame_retention_seconds', 60);
        [$owner, $server] = $this->resources();
        $grant = app(OpenServerTroubleshootingSessionAction::class)->handle($server, $owner);
        $frame = app(QueueServerTroubleshootingInputFrameAction::class)->handle($grant->session, $owner, $grant->token, 'old');
        ServerTroubleshootingFrame::query()->whereKey($frame->id)->update(['created_at' => now()->subMinutes(10)]);

        $this->assertSame(1, app(PruneServerTroubleshootingFramesAction::class)->handle(1));
        $this->assertDatabaseMissing('server_troubleshooting_frames', ['id' => $frame->id]);
    }

    /** @return ServerTroubleshootingBrokerLease */
    private function claim(ServerTroubleshootingSession $session, int $processId): ServerTroubleshootingBrokerLease
    {
        $lease = app(ClaimServerTroubleshootingBrokerLeaseAction::class)->handle($session, $processId);
        $this->assertNotNull($lease);

        return $lease;
    }

    /** @return array{0: User, 1: Server} */
    private function resources(): array
    {
        $owner = User::factory()->create();
        $provider = $owner->providers()->create([
            'name' => 'DigitalOcean',
            'description' => 'Cloud provider',
            'provider' => Provider::TYPE_DIGITALOCEAN,
            'token' => 'cloud-secret',
        ]);
        $server = $owner->servers()->create([
            'provider_id' => $provider->id,
            'name' => 'Production',
            'public_ip' => '192.0.2.10',
            'ssh_private_key' => 'private-key',
            'ssh_host_key' => '192.0.2.10 ssh-ed25519 AAAAhost-key',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);

        return [$owner, $server];
    }

    private function member(User $owner, string $role): User
    {
        $member = User::factory()->create(['current_organization_id' => $owner->current_organization_id]);
        $owner->currentOrganization->members()->attach($member, ['role' => $role]);

        return $member;
    }
}

final class FakeServerTroubleshootingConnection implements ServerTroubleshootingConnection
{
    /** @var list<string> */
    public array $writes = [];

    public bool $closed = false;

    public function __construct(private string $output = '') {}

    public function write(string $input): void
    {
        $this->writes[] = $input;
    }

    public function read(): string
    {
        $output = $this->output;
        $this->output = '';

        return $output;
    }

    public function resize(ServerTroubleshootingTerminalSize $size): void {}

    public function isRunning(): bool
    {
        return ! $this->closed;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}

final class FakeServerTroubleshootingTransport implements ServerTroubleshootingTransport
{
    public int $connections = 0;

    public function __construct(private readonly FakeServerTroubleshootingConnection $connection) {}

    public function connect(Server $server, ServerTroubleshootingTerminalSize $size): ServerTroubleshootingConnection
    {
        $this->connections++;

        return $this->connection;
    }
}
