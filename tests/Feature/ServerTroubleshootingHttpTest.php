<?php

namespace Tests\Feature;

use App\Modules\Deployer\Actions\Server\OpenServerTroubleshootingSessionAction;
use App\Modules\Deployer\Actions\Server\RevokeServerTroubleshootingSessionAction;
use App\Modules\Deployer\Enums\ServerTroubleshootingFrameDirection;
use App\Modules\Deployer\Models\Provider;
use App\Modules\Deployer\Models\Server;
use App\Modules\Deployer\Models\ServerTroubleshootingFrame;
use App\Modules\Deployer\Models\ServerTroubleshootingSession;
use App\Modules\Deployer\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ServerTroubleshootingHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_open_a_session_without_remote_work_and_receives_only_a_one_time_token(): void
    {
        [$owner, $server] = $this->resources();

        $response = $this->actingAs($owner)->postJson(route('servers.troubleshooting-sessions.store', $server));

        $response
            ->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.status', ServerTroubleshootingSession::STATUS_CONNECTING)
            ->assertJsonPath('data.can_execute', true)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'token',
                    'status',
                    'expires_at',
                    'idle_expires_at',
                    'last_seen_at',
                    'connected_at',
                    'closed_at',
                    'close_reason',
                    'can_execute',
                    'input_sequence',
                    'output_sequence',
                ],
            ]);

        $token = (string) $response->json('data.token');
        $session = ServerTroubleshootingSession::query()->where('public_id', $response->json('data.id'))->firstOrFail();

        $this->assertSame(64, strlen($token));
        $this->assertFalse($session->matchesGrant('not-the-grant'));
        $this->assertNotSame($token, $session->getRawOriginal('grant_hash'));
        $this->assertArrayNotHasKey('grant_hash', $session->toArray());
        $this->assertDatabaseCount('server_troubleshooting_frames', 0);
    }

    public function test_viewer_can_open_a_session_but_is_not_granted_shell_execution(): void
    {
        [$owner, $server] = $this->resources();
        $viewer = $this->member($owner, 'viewer');

        $response = $this->actingAs($viewer)->postJson(route('servers.troubleshooting-sessions.store', $server));

        $response
            ->assertCreated()
            ->assertJsonPath('data.can_execute', false);
    }

    public function test_status_revalidates_the_grant_and_refreshes_only_safe_metadata(): void
    {
        [$owner, $server] = $this->resources();
        [$session, $token] = $this->openSession($server, $owner);
        $lastSeen = $session->fresh()->last_seen_at;

        $this->travel(5)->seconds();
        $response = $this->actingAs($owner)->getJson(
            route('servers.troubleshooting-sessions.show', [$server, $session]),
            ['Authorization' => 'Bearer '.$token],
        );

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $session->public_id)
            ->assertJsonPath('data.status', ServerTroubleshootingSession::STATUS_CONNECTING)
            ->assertJsonMissingPath('data.token');
        $this->assertTrue($session->fresh()->last_seen_at->greaterThan($lastSeen));
        $this->assertStringNotContainsString($token, $response->getContent());
    }

    public function test_status_rejects_a_missing_or_invalid_grant_before_touching_the_session(): void
    {
        [$owner, $server] = $this->resources();
        [$session, $token] = $this->openSession($server, $owner);
        $lastSeen = $session->fresh()->last_seen_at;

        $this->actingAs($owner)
            ->getJson(route('servers.troubleshooting-sessions.show', [$server, $session]))
            ->assertForbidden();
        $this->actingAs($owner)
            ->getJson(route('servers.troubleshooting-sessions.show', [$server, $session]), ['Authorization' => 'Bearer invalid'])
            ->assertForbidden();

        $this->assertTrue($session->fresh()->last_seen_at->equalTo($lastSeen));
        $this->assertTrue($session->fresh()->matchesGrant($token));
    }

    public function test_foreign_actor_is_denied_before_bearer_parsing(): void
    {
        [$owner, $server] = $this->resources();
        [$session] = $this->openSession($server, $owner);
        $intruder = User::factory()->create();

        $this->actingAs($intruder)
            ->getJson(route('servers.troubleshooting-sessions.show', [$server, $session]))
            ->assertForbidden();
    }

    public function test_nested_binding_does_not_expose_a_session_under_another_server(): void
    {
        [$owner, $server] = $this->resources();
        [, $otherServer] = $this->resources();
        [$session, $token] = $this->openSession($server, $owner);

        $this->actingAs($owner)
            ->getJson(
                route('servers.troubleshooting-sessions.show', [$otherServer, $session]),
                ['Authorization' => 'Bearer '.$token],
            )
            ->assertNotFound();
    }

    public function test_status_terminalizes_an_expired_session_without_returning_its_token(): void
    {
        [$owner, $server] = $this->resources();
        [$session, $token] = $this->openSession($server, $owner);
        $session->update(['expires_at' => now()->subSecond()]);

        $response = $this->actingAs($owner)->getJson(
            route('servers.troubleshooting-sessions.show', [$server, $session]),
            ['Authorization' => 'Bearer '.$token],
        );

        $response
            ->assertOk()
            ->assertJsonPath('data.status', ServerTroubleshootingSession::STATUS_EXPIRED)
            ->assertJsonPath('data.close_reason', ServerTroubleshootingSession::CLOSE_REASON_EXPIRED)
            ->assertJsonPath('data.can_execute', false)
            ->assertJsonMissingPath('data.token');
    }

    public function test_close_is_idempotent_and_clears_the_broker_ownership_boundary(): void
    {
        [$owner, $server] = $this->resources();
        [$session, $token] = $this->openSession($server, $owner);

        $first = $this->actingAs($owner)->deleteJson(
            route('servers.troubleshooting-sessions.destroy', [$server, $session]),
            [],
            ['Authorization' => 'Bearer '.$token],
        );
        $first
            ->assertOk()
            ->assertJsonPath('data.status', ServerTroubleshootingSession::STATUS_CLOSED)
            ->assertJsonPath('data.close_reason', ServerTroubleshootingSession::CLOSE_REASON_USER)
            ->assertJsonPath('closed', true);

        $second = $this->actingAs($owner)->deleteJson(
            route('servers.troubleshooting-sessions.destroy', [$server, $session]),
            [],
            ['Authorization' => 'Bearer '.$token],
        );
        $second
            ->assertOk()
            ->assertJsonPath('data.status', ServerTroubleshootingSession::STATUS_CLOSED)
            ->assertJsonPath('closed', false);

        $this->assertNull($session->fresh()->broker_lease_hash);
        $this->assertNull($session->fresh()->broker_process_id);
    }

    public function test_revoked_grants_cannot_be_reused_and_reconnect_requires_a_new_session(): void
    {
        [$owner, $server] = $this->resources();
        [$session, $token] = $this->openSession($server, $owner);

        app(RevokeServerTroubleshootingSessionAction::class)->handle($session);

        $this->actingAs($owner)
            ->postJson(
                route('servers.troubleshooting-sessions.input', [$server, $session]),
                ['input' => "whoami\n"],
                ['Authorization' => 'Bearer '.$token],
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('session');

        $replacement = $this->actingAs($owner)
            ->postJson(route('servers.troubleshooting-sessions.store', $server))
            ->assertCreated();

        $this->assertNotSame($session->public_id, $replacement->json('data.id'));
        $this->assertNotSame($token, $replacement->json('data.token'));
        $this->assertSame(
            ServerTroubleshootingSession::STATUS_REVOKED,
            $session->fresh()->status,
        );
    }

    public function test_owner_can_queue_input_without_echoing_the_sensitive_payload(): void
    {
        [$owner, $server] = $this->resources();
        [$session, $token] = $this->openSession($server, $owner);
        $payload = "echo sensitive-input\n";

        $response = $this->actingAs($owner)->postJson(
            route('servers.troubleshooting-sessions.input', [$server, $session]),
            ['input' => $payload],
            ['Authorization' => 'Bearer '.$token],
        );

        $response
            ->assertAccepted()
            ->assertJsonPath('data.accepted', true)
            ->assertJsonPath('data.sequence', 1)
            ->assertJsonPath('data.bytes', strlen($payload))
            ->assertJsonMissingPath('data.payload');
        $this->assertStringNotContainsString($payload, $response->getContent());
        $this->assertDatabaseCount('server_troubleshooting_frames', 1);
        $response->assertSessionMissing('_old_input');
    }

    public function test_execute_is_required_before_malformed_input_is_parsed_and_no_frame_is_written(): void
    {
        [$owner, $server] = $this->resources();
        $viewer = $this->member($owner, 'viewer');
        $open = $this->actingAs($viewer)->postJson(
            route('servers.troubleshooting-sessions.store', $server),
        )->assertCreated();
        $session = ServerTroubleshootingSession::query()
            ->where('public_id', $open->json('data.id'))
            ->firstOrFail();
        $token = (string) $open->json('data.token');

        $this->actingAs($viewer)
            ->postJson(
                route('servers.troubleshooting-sessions.input', [$server, $session]),
                ['input' => ['not-a-string' => 'should-not-be-read']],
                ['Authorization' => 'Bearer '.$token],
            )
            ->assertForbidden();

        $this->assertDatabaseCount('server_troubleshooting_frames', 0);
    }

    public function test_input_validation_is_bounded_without_flashing_or_persisting_the_payload(): void
    {
        [$owner, $server] = $this->resources();
        [$session, $token] = $this->openSession($server, $owner);
        $payload = str_repeat('sensitive-command', 500);

        $response = $this->actingAs($owner)->postJson(
            route('servers.troubleshooting-sessions.input', [$server, $session]),
            ['input' => $payload],
            ['Authorization' => 'Bearer '.$token],
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('input');
        $this->assertStringNotContainsString($payload, $response->getContent());
        $response->assertSessionMissing('_old_input');
        $this->assertDatabaseCount('server_troubleshooting_frames', 0);
    }

    public function test_output_polling_is_bounded_ordered_and_does_not_return_ciphertext(): void
    {
        [$owner, $server] = $this->resources();
        [$session, $token] = $this->openSession($server, $owner);
        $first = $session->frames()->create([
            'direction' => ServerTroubleshootingFrameDirection::Output,
            'sequence' => 1,
            'payload' => "first-output\n",
            'payload_bytes' => strlen("first-output\n"),
        ]);
        $session->frames()->create([
            'direction' => ServerTroubleshootingFrameDirection::Output,
            'sequence' => 2,
            'payload' => "second-output\n",
            'payload_bytes' => strlen("second-output\n"),
        ]);
        $session->update(['output_sequence' => 2]);

        $response = $this->actingAs($owner)->getJson(
            route('servers.troubleshooting-sessions.output', [$server, $session]).'?after=0&limit=1',
            ['Authorization' => 'Bearer '.$token],
        );

        $response
            ->assertOk()
            ->assertJsonPath('data.after', 0)
            ->assertJsonPath('data.next_after', 1)
            ->assertJsonPath('data.frames.0.sequence', 1)
            ->assertJsonPath('data.frames.0.payload', "first-output\n")
            ->assertJsonPath('data.frames.0.bytes', strlen("first-output\n"))
            ->assertJsonMissingPath('data.frames.0.id');
        $this->assertNotSame(
            "first-output\n",
            DB::table('server_troubleshooting_frames')->whereKey($first->id)->value('payload'),
        );
    }

    public function test_output_acknowledgment_removes_only_acknowledged_output_from_future_polls(): void
    {
        [$owner, $server] = $this->resources();
        [$session, $token] = $this->openSession($server, $owner);
        $session->frames()->createMany([
            [
                'direction' => ServerTroubleshootingFrameDirection::Output,
                'sequence' => 1,
                'payload' => "first-output\n",
                'payload_bytes' => strlen("first-output\n"),
            ],
            [
                'direction' => ServerTroubleshootingFrameDirection::Output,
                'sequence' => 2,
                'payload' => "second-output\n",
                'payload_bytes' => strlen("second-output\n"),
            ],
        ]);
        $session->update(['output_sequence' => 2]);

        $response = $this->actingAs($owner)->postJson(
            route('servers.troubleshooting-sessions.output.acknowledge', [$server, $session]),
            ['through' => 1],
            ['Authorization' => 'Bearer '.$token],
        );

        $response
            ->assertOk()
            ->assertJsonPath('data.acknowledged', 1)
            ->assertJsonPath('data.through', 1);
        $this->assertNotNull(
            ServerTroubleshootingFrame::query()
                ->where('server_troubleshooting_session_id', $session->id)
                ->where('sequence', 1)
                ->value('acknowledged_at'),
        );

        $remaining = $this->actingAs($owner)->getJson(
            route('servers.troubleshooting-sessions.output', [$server, $session]).'?after=0&limit=10',
            ['Authorization' => 'Bearer '.$token],
        );

        $remaining
            ->assertOk()
            ->assertJsonCount(1, 'data.frames')
            ->assertJsonPath('data.frames.0.sequence', 2);
    }

    public function test_resize_is_queued_as_a_validated_control_frame_without_exposing_the_frame_payload(): void
    {
        [$owner, $server] = $this->resources();
        [$session, $token] = $this->openSession($server, $owner);

        $response = $this->actingAs($owner)->postJson(
            route('servers.troubleshooting-sessions.resize', [$server, $session]),
            ['columns' => 120, 'rows' => 40],
            ['Authorization' => 'Bearer '.$token],
        );

        $response
            ->assertAccepted()
            ->assertJsonPath('data.accepted', true)
            ->assertJsonPath('data.sequence', 1)
            ->assertJsonPath('data.bytes', strlen("stty rows 40 cols 120\n"))
            ->assertJsonMissingPath('data.payload');
        $this->assertSame(
            "stty rows 40 cols 120\n",
            $session->frames()->firstOrFail()->payload,
        );
    }

    public function test_resize_rejects_unsupported_dimensions_without_queuing_a_control_frame(): void
    {
        [$owner, $server] = $this->resources();
        [$session, $token] = $this->openSession($server, $owner);

        $this->actingAs($owner)
            ->postJson(
                route('servers.troubleshooting-sessions.resize', [$server, $session]),
                ['columns' => 10, 'rows' => 24],
                ['Authorization' => 'Bearer '.$token],
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('terminal');

        $this->assertDatabaseCount('server_troubleshooting_frames', 0);
    }

    /** @return array{0: ServerTroubleshootingSession, 1: string} */
    private function openSession(Server $server, User $user): array
    {
        $grant = app(OpenServerTroubleshootingSessionAction::class)->handle($server, $user);

        return [$grant->session, $grant->token];
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
