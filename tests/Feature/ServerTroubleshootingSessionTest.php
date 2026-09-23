<?php

namespace Tests\Feature;

use App\Modules\Deployer\Actions\Server\CloseServerTroubleshootingSessionAction;
use App\Modules\Deployer\Actions\Server\ExpireServerTroubleshootingSessionsAction;
use App\Modules\Deployer\Actions\Server\OpenServerTroubleshootingSessionAction;
use App\Modules\Deployer\Actions\Server\RevokeServerTroubleshootingSessionAction;
use App\Modules\Deployer\Actions\Server\TouchServerTroubleshootingSessionAction;
use App\Modules\Deployer\Enums\ServerTroubleshootingSessionStatus;
use App\Modules\Deployer\Models\Provider;
use App\Modules\Deployer\Models\Server;
use App\Modules\Deployer\Models\ServerTroubleshootingSession;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Policies\ServerTroubleshootingSessionPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ServerTroubleshootingSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_issue_an_opaque_grant_without_remote_work_or_queueing_a_job(): void
    {
        Queue::fake();
        [$owner, $server] = $this->resources();

        $grant = app(OpenServerTroubleshootingSessionAction::class)->handle($server, $owner);

        $session = $grant->session->fresh();
        $this->assertSame(ServerTroubleshootingSession::STATUS_CONNECTING, $session->status);
        $this->assertSame($owner->id, $session->user_id);
        $this->assertNotSame($grant->token, $session->grant_hash);
        $this->assertArrayNotHasKey('grant_hash', $session->toArray());
        $this->assertStringNotContainsString($grant->token, serialize($session));
        $this->assertTrue($session->matchesGrant($grant->token));
        $this->assertNotNull($session->expires_at);
        $this->assertNotNull($session->idle_expires_at);
        Queue::assertNothingPushed();
    }

    public function test_connect_and_execute_permissions_are_separate(): void
    {
        [$owner, $server] = $this->resources();
        $viewer = $this->member($owner, 'viewer');
        $operator = $this->member($owner, 'operator');
        $grant = app(OpenServerTroubleshootingSessionAction::class)->handle($server, $viewer);
        $policy = app(ServerTroubleshootingSessionPolicy::class);

        $this->assertTrue(Gate::forUser($viewer)->allows('connect', $server));
        $this->assertFalse(Gate::forUser($viewer)->allows('execute', $server));
        $this->assertTrue(Gate::forUser($operator)->allows('connect', $server));
        $this->assertTrue(Gate::forUser($operator)->allows('execute', $server));
        $this->assertTrue($policy->connect($viewer, $grant->session));
        $this->assertFalse($policy->execute($viewer, $grant->session));
        $this->assertFalse($policy->execute($operator, $grant->session));
    }

    public function test_duplicate_actor_and_server_capacity_are_rejected_without_new_sessions(): void
    {
        [$owner, $server] = $this->resources();
        $action = app(OpenServerTroubleshootingSessionAction::class);
        $action->handle($server, $owner);

        try {
            $action->handle($server, $owner);
            $this->fail('A second session for the same actor should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame('You already have an active troubleshooting session for this server.', $exception->errors()['session'][0]);
        }

        $this->assertDatabaseCount('server_troubleshooting_sessions', 1);
    }

    public function test_expired_active_sessions_are_recovered_before_capacity_is_checked(): void
    {
        [$owner, $server] = $this->resources();
        $old = $server->troubleshootingSessions()->create($this->sessionAttributes($owner, [
            'status' => ServerTroubleshootingSession::STATUS_CONNECTED,
            'expires_at' => now()->subMinute(),
            'idle_expires_at' => now()->subMinute(),
        ]));

        $grant = app(OpenServerTroubleshootingSessionAction::class)->handle($server, $owner);

        $this->assertSame(ServerTroubleshootingSession::STATUS_EXPIRED, $old->fresh()->status);
        $this->assertSame(ServerTroubleshootingSession::STATUS_CONNECTING, $grant->session->fresh()->status);
    }

    public function test_inactive_or_unpinned_servers_fail_before_a_session_is_persisted(): void
    {
        [$owner, $server] = $this->resources();
        $server->update(['ssh_host_key' => null]);

        try {
            app(OpenServerTroubleshootingSessionAction::class)->handle($server, $owner);
            $this->fail('An unpinned server should not receive a session grant.');
        } catch (ValidationException $exception) {
            $this->assertSame('A pinned SSH host identity is required before a troubleshooting session can open.', $exception->errors()['session'][0]);
        }

        $server->update([
            'ssh_host_key' => '192.0.2.10 ssh-ed25519 AAAAhost-key',
            'provisioning_status' => Server::STATUS_FAILED,
        ]);

        try {
            app(OpenServerTroubleshootingSessionAction::class)->handle($server, $owner);
            $this->fail('An inactive server should not receive a session grant.');
        } catch (ValidationException $exception) {
            $this->assertSame('Troubleshooting sessions are available only for active servers.', $exception->errors()['session'][0]);
        }

        $this->assertDatabaseCount('server_troubleshooting_sessions', 0);
    }

    public function test_touch_revalidates_the_grant_and_extends_idle_time_without_extending_absolute_expiry(): void
    {
        [$owner, $server] = $this->resources();
        $grant = app(OpenServerTroubleshootingSessionAction::class)->handle($server, $owner);
        $session = $grant->session->fresh();
        $absolute = $session->expires_at;
        $idle = $session->idle_expires_at;

        $this->travel(10)->seconds();
        $this->assertTrue(app(TouchServerTroubleshootingSessionAction::class)->handle($session, $owner, $grant->token));

        $updated = $session->fresh();
        $this->assertSame(ServerTroubleshootingSession::STATUS_CONNECTING, $updated->status);
        $this->assertTrue($updated->last_seen_at->greaterThan($session->last_seen_at));
        $this->assertTrue($updated->idle_expires_at->greaterThan($idle));
        $this->assertTrue($updated->expires_at->equalTo($absolute));
    }

    public function test_invalid_grants_cannot_touch_a_session(): void
    {
        [$owner, $server] = $this->resources();
        $grant = app(OpenServerTroubleshootingSessionAction::class)->handle($server, $owner);
        $intruder = User::factory()->create();
        $action = app(TouchServerTroubleshootingSessionAction::class);

        $this->expectException(AuthorizationException::class);
        $action->handle($grant->session, $intruder, $grant->token);
    }

    public function test_revoked_membership_cannot_touch_a_session(): void
    {
        [$owner, $server] = $this->resources();
        $member = $this->member($owner, 'viewer');
        $grant = app(OpenServerTroubleshootingSessionAction::class)->handle($server, $member);
        $owner->currentOrganization->members()->detach($member);

        $this->expectException(AuthorizationException::class);
        app(TouchServerTroubleshootingSessionAction::class)->handle($grant->session, $member, $grant->token);
    }

    public function test_expired_touch_and_inactive_server_have_distinct_terminal_outcomes(): void
    {
        [$owner, $server] = $this->resources();
        $expired = app(OpenServerTroubleshootingSessionAction::class)->handle($server, $owner);
        $expired->session->update(['expires_at' => now()->subSecond()]);

        $this->assertFalse(app(TouchServerTroubleshootingSessionAction::class)->handle($expired->session, $owner, $expired->token));
        $this->assertSame(ServerTroubleshootingSession::STATUS_EXPIRED, $expired->session->fresh()->status);

        $server->update(['provisioning_status' => Server::STATUS_ACTIVE]);
        $second = app(OpenServerTroubleshootingSessionAction::class)->handle($server, $owner);
        $server->update(['provisioning_status' => Server::STATUS_FAILED]);

        $this->assertFalse(app(TouchServerTroubleshootingSessionAction::class)->handle($second->session, $owner, $second->token));
        $this->assertSame(ServerTroubleshootingSession::STATUS_FAILED, $second->session->fresh()->status);
    }

    public function test_owner_can_close_a_session_and_repeated_close_is_a_noop(): void
    {
        [$owner, $server] = $this->resources();
        $grant = app(OpenServerTroubleshootingSessionAction::class)->handle($server, $owner);
        $action = app(CloseServerTroubleshootingSessionAction::class);

        $this->assertTrue($action->handle($grant->session, $owner, $grant->token));
        $this->assertFalse($action->handle($grant->session, $owner, $grant->token));
        $closed = $grant->session->fresh();
        $this->assertSame(ServerTroubleshootingSessionStatus::Closed, $closed->statusEnum());
        $this->assertSame(ServerTroubleshootingSession::CLOSE_REASON_USER, $closed->close_reason);
    }

    public function test_internal_revocation_wins_over_late_activity_and_is_idempotent(): void
    {
        [$owner, $server] = $this->resources();
        $grant = app(OpenServerTroubleshootingSessionAction::class)->handle($server, $owner);
        $action = app(RevokeServerTroubleshootingSessionAction::class);

        $this->assertTrue($action->handle($grant->session));
        $this->assertFalse($action->handle($grant->session));
        $this->assertSame(ServerTroubleshootingSession::STATUS_REVOKED, $grant->session->fresh()->status);

        $this->assertFalse(app(TouchServerTroubleshootingSessionAction::class)->handle($grant->session, $owner, $grant->token));
    }

    public function test_expiry_action_marks_only_a_bounded_batch_and_does_not_contact_servers(): void
    {
        [$owner, $server] = $this->resources();
        $other = $this->resources()[1];
        foreach ([$server, $other] as $target) {
            $target->troubleshootingSessions()->create($this->sessionAttributes($owner, [
                'expires_at' => now()->subMinute(),
                'idle_expires_at' => now()->subMinute(),
            ]));
        }
        $live = $server->troubleshootingSessions()->create($this->sessionAttributes($owner));

        $expired = app(ExpireServerTroubleshootingSessionsAction::class)->handle(1);

        $this->assertSame(1, $expired);
        $this->assertSame(1, ServerTroubleshootingSession::query()->where('status', ServerTroubleshootingSession::STATUS_EXPIRED)->count());
        $this->assertSame(ServerTroubleshootingSession::STATUS_CONNECTING, $live->fresh()->status);
    }

    public function test_session_records_are_deleted_with_the_server(): void
    {
        [$owner, $server] = $this->resources();
        $grant = app(OpenServerTroubleshootingSessionAction::class)->handle($server, $owner);

        Server::withoutEvents(fn () => $server->delete());

        $this->assertDatabaseMissing('server_troubleshooting_sessions', ['id' => $grant->session->id]);
    }

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

    private function sessionAttributes(User $user, array $overrides = []): array
    {
        $token = Str::random(64);

        return array_merge([
            'user_id' => $user->id,
            'public_id' => (string) Str::uuid(),
            'status' => ServerTroubleshootingSession::STATUS_CONNECTING,
            'grant_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(10),
            'idle_expires_at' => now()->addMinutes(5),
            'last_seen_at' => now(),
        ], $overrides);
    }
}
