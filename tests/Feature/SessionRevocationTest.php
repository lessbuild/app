<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SessionRevocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_change_keeps_the_current_browser_authenticated_and_revokes_other_devices(): void
    {
        Event::fake([OtherDeviceLogout::class]);
        $user = User::factory()->create([
            'password' => Hash::make('current-password'),
        ]);

        $this->actingAs($user)->get(route('account.index'))->assertSuccessful();

        $this->patch(route('account.password.update'), [
            'current_password' => 'current-password',
            'password' => 'replacement-password',
            'password_confirmation' => 'replacement-password',
        ])->assertSessionHas('password_status');

        Event::assertDispatched(OtherDeviceLogout::class, fn (OtherDeviceLogout $event) => $event->user->is($user));
        $this->assertAuthenticatedAs($user);
        $this->get(route('account.index'))->assertSuccessful();
    }

    public function test_a_session_with_a_stale_password_hash_is_forced_to_reauthenticate(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
        ]);
        $oldPasswordHash = $user->password;
        $user->update(['password' => Hash::make('new-password')]);

        $this->actingAs($user)
            ->withSession([
                'password_hash_web' => $oldPasswordHash,
                'revoked_session_data' => 'must-be-cleared',
            ])
            ->get(route('account.index'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('url.intended', route('account.index'))
            ->assertSessionMissing('password_hash_web')
            ->assertSessionMissing('revoked_session_data');

        $this->assertGuest();
    }

    public function test_a_json_request_with_a_stale_session_returns_an_authentication_error_without_redirecting(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
        ]);
        $oldPasswordHash = $user->password;
        $user->update(['password' => Hash::make('new-password')]);

        $this->actingAs($user)
            ->withSession([
                'password_hash_web' => $oldPasswordHash,
                'revoked_session_data' => 'must-be-cleared',
            ])
            ->getJson(route('account.index'))
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.'])
            ->assertHeaderMissing('Location')
            ->assertSessionMissing('url.intended')
            ->assertSessionMissing('password_hash_web')
            ->assertSessionMissing('revoked_session_data');

        $this->assertGuest();
    }

    public function test_a_session_without_a_stored_password_hash_is_initialized_normally(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('account.index'))->assertSuccessful();

        $this->assertNotNull(session('password_hash_web'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_user_can_revoke_other_sessions_without_changing_their_password(): void
    {
        Event::fake([OtherDeviceLogout::class]);
        $user = User::factory()->create([
            'password' => Hash::make('current-password'),
        ]);
        $previousHash = $user->password;

        $this->actingAs($user)->get(route('account.index'))->assertSuccessful();
        $this->post(route('account.sessions.revoke'), [
            'current_password' => 'current-password',
        ])->assertSessionHas('sessions_status', 'Other browser sessions logged out.');

        Event::assertDispatched(OtherDeviceLogout::class, fn (OtherDeviceLogout $event) => $event->user->is($user));
        $this->assertNotSame($previousHash, $user->fresh()->password);
        $this->assertTrue(Hash::check('current-password', $user->fresh()->password));
        $this->assertAuthenticatedAs($user);
        $this->get(route('account.index'))->assertSuccessful();

        $this->actingAs($user->fresh())
            ->withSession(['password_hash_web' => $previousHash])
            ->get(route('account.index'))
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_email_change_keeps_current_browser_and_revokes_stale_sessions(): void
    {
        Event::fake([OtherDeviceLogout::class]);
        Notification::fake();
        $user = User::factory()->create([
            'password' => Hash::make('current-password'),
        ]);
        $previousHash = $user->password;

        $this->actingAs($user)->get(route('account.index'))->assertSuccessful();
        $this->patch(route('account.profile.update'), [
            'name' => $user->name,
            'email' => 'replacement@example.test',
            'current_password' => 'current-password',
        ])->assertSessionHas('status', 'verification-link-sent');

        Event::assertDispatched(OtherDeviceLogout::class, fn (OtherDeviceLogout $event): bool => $event->user->is($user));
        $this->assertNotSame($previousHash, $user->fresh()->password);
        $this->assertTrue(Hash::check('current-password', $user->fresh()->password));
        $this->assertAuthenticatedAs($user);
        $this->get(route('account.index'))->assertSuccessful();

        $this->actingAs($user->fresh())
            ->withSession(['password_hash_web' => $previousHash])
            ->get(route('account.index'))
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_session_revocation_rejects_an_incorrect_current_password(): void
    {
        Event::fake([OtherDeviceLogout::class]);
        $user = User::factory()->create([
            'password' => Hash::make('current-password'),
        ]);
        $previousHash = $user->password;

        $this->actingAs($user)->post(route('account.sessions.revoke'), [
            'current_password' => 'incorrect-password',
        ])->assertSessionHasErrors(['current_password'], errorBag: 'sessions');

        Event::assertNotDispatched(OtherDeviceLogout::class);
        $this->assertSame($previousHash, $user->fresh()->password);
        $this->assertAuthenticatedAs($user);
    }

    public function test_social_only_user_is_prompted_to_set_a_password_before_revoking_sessions(): void
    {
        $user = User::factory()->create([
            'password_set_at' => null,
            'auth_type' => 'github',
        ]);

        $this->actingAs($user)->get(route('account.index'))
            ->assertSuccessful()
            ->assertSee('Set a local password before revoking other browser sessions.')
            ->assertDontSee(route('account.sessions.revoke'));

        $this->post(route('account.sessions.revoke'), [
            'current_password' => 'unknown-password',
        ])->assertSessionHasErrors(['current_password'], errorBag: 'sessions');
    }
}
