<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BrowserSessionManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_lists_only_recent_owner_sessions_without_loading_payloads(): void
    {
        $this->useDatabaseSessions();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $visibleId = str_repeat('a', 40);

        $this->insertSession(
            $visibleId,
            $user,
            '192.0.2.20',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/140.0 Safari/537.36 payload-secret-marker',
        );
        $this->insertSession(
            str_repeat('b', 40),
            $other,
            '198.51.100.99',
            'Other owner browser',
        );
        $this->insertSession(
            str_repeat('c', 40),
            $user,
            '203.0.113.77',
            'Expired owner browser',
            now()->subMinutes(121)->timestamp,
        );

        $this->actingAs($user)->get(route('account.index'))
            ->assertSuccessful()
            ->assertSee('Chrome on macOS')
            ->assertSee('192.0.2.20')
            ->assertSee(route('account.sessions.destroy', $visibleId))
            ->assertSee('name="session_id" value="'.$visibleId.'"', false)
            ->assertDontSee('payload-secret-value')
            ->assertDontSee('payload-secret-marker')
            ->assertDontSee('198.51.100.99')
            ->assertDontSee('203.0.113.77');
    }

    public function test_user_can_revoke_one_owned_session_with_their_current_password(): void
    {
        $this->useDatabaseSessions();
        $user = User::factory()->create([
            'password' => Hash::make('current-password'),
        ]);
        $sessionId = str_repeat('d', 40);
        $this->insertSession($sessionId, $user);

        $this->actingAs($user)->delete(route('account.sessions.destroy', $sessionId), [
            'session_id' => $sessionId,
            'current_password' => 'current-password',
        ])->assertSessionHas('sessions_status', 'Browser session logged out.');

        $this->assertDatabaseMissing('sessions', ['id' => $sessionId]);
        $this->assertDatabaseHas('events', [
            'user_id' => $user->id,
            'category' => 'account',
            'event' => 'A browser session was logged out.',
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
        ]);
    }

    public function test_targeted_revocation_rejects_a_wrong_password_and_another_owners_session(): void
    {
        $this->useDatabaseSessions();
        $user = User::factory()->create([
            'password' => Hash::make('current-password'),
        ]);
        $other = User::factory()->create();
        $ownedId = str_repeat('e', 40);
        $otherId = str_repeat('f', 40);
        $this->insertSession($ownedId, $user);
        $this->insertSession($otherId, $other);

        $this->actingAs($user)->delete(route('account.sessions.destroy', $ownedId), [
            'session_id' => $ownedId,
            'current_password' => 'incorrect-password',
        ])->assertSessionHasErrors(['current_password'], errorBag: 'sessions')
            ->assertSessionMissing('_old_input.current_password');
        $this->assertDatabaseHas('sessions', ['id' => $ownedId, 'user_id' => $user->id]);

        $this->delete(route('account.sessions.destroy', $otherId), [
            'session_id' => $otherId,
            'current_password' => 'current-password',
        ])->assertSessionHas('sessions_status', 'That browser session is no longer active.');
        $this->assertDatabaseHas('sessions', ['id' => $otherId, 'user_id' => $other->id]);
    }

    public function test_current_browser_cannot_be_revoked_individually(): void
    {
        $this->useDatabaseSessions();
        $user = User::factory()->create([
            'password' => Hash::make('current-password'),
        ]);
        $currentId = str_repeat('g', 40);
        $this->insertSession($currentId, $user);

        $this->withCookie((string) config('session.cookie'), $currentId)
            ->actingAs($user)
            ->delete(route('account.sessions.destroy', $currentId), [
                'session_id' => $currentId,
                'current_password' => 'current-password',
            ])->assertSessionHas('sessions_error', 'You cannot log out the browser you are using now.');

        $this->assertDatabaseHas('sessions', ['id' => $currentId, 'user_id' => $user->id]);
        $this->assertAuthenticatedAs($user);
    }

    public function test_bulk_revocation_immediately_removes_every_other_database_session(): void
    {
        $this->useDatabaseSessions();
        $user = User::factory()->create([
            'password' => Hash::make('current-password'),
        ]);
        $currentId = str_repeat('h', 40);
        $otherIds = [str_repeat('i', 40), str_repeat('j', 40)];
        $this->insertSession($currentId, $user);
        foreach ($otherIds as $otherId) {
            $this->insertSession($otherId, $user);
        }

        $this->withCookie((string) config('session.cookie'), $currentId)
            ->actingAs($user)
            ->post(route('account.sessions.revoke'), [
                'current_password' => 'current-password',
            ])->assertSessionHas('sessions_status', 'Other browser sessions logged out.');

        foreach ($otherIds as $otherId) {
            $this->assertDatabaseMissing('sessions', ['id' => $otherId]);
        }
        $this->assertSame(1, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertAuthenticatedAs($user);
    }

    private function useDatabaseSessions(): void
    {
        config([
            'session.driver' => 'database',
            'session.encrypt' => true,
        ]);
        $this->app['session']->forgetDrivers();
    }

    private function insertSession(
        string $id,
        User $user,
        string $ipAddress = '192.0.2.30',
        string $userAgent = 'Mozilla/5.0 Firefox/140.0',
        ?int $lastActivity = null,
    ): void {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'payload' => base64_encode('payload-secret-value'),
            'last_activity' => $lastActivity ?? now()->timestamp,
        ]);
    }
}
