<?php

namespace Tests\Feature;

use App\Actions\Server\OpenServerTroubleshootingSessionAction;
use App\Models\Provider;
use App\Models\Server;
use App\Models\ServerTroubleshootingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
