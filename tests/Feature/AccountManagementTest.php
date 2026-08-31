<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AccountManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_page_requires_authentication(): void
    {
        $this->get(route('account.index'))->assertRedirect(route('login'));
        $this->patch(route('account.profile.update'))->assertRedirect(route('login'));
        $this->patch(route('account.password.update'))->assertRedirect(route('login'));
        $this->post(route('account.sessions.revoke'))->assertRedirect(route('login'));
        $this->delete(route('account.social.destroy', 'github'))->assertRedirect(route('login'));
    }

    public function test_account_page_shows_the_authenticated_users_forms(): void
    {
        config([
            'services.github.client_id' => 'client-id',
            'services.github.client_secret' => 'client-secret',
            'services.github.redirect' => 'https://app.example/auth/social/callback/github',
        ]);
        $user = User::factory()->create();

        $this->assertArrayNotHasKey('password_set_at', $user->toArray());

        $this->actingAs($user)->get(route('account.index'))
            ->assertSuccessful()
            ->assertSee($user->name)
            ->assertSee($user->email)
            ->assertSee('Save profile')
            ->assertSee('Update password')
            ->assertSee(route('account.sessions.revoke'))
            ->assertSee('Log out other sessions')
            ->assertSee(route('account.social.connect', 'github'));
    }

    public function test_user_can_update_their_profile(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)->patch(route('account.profile.update'), [
            'name' => 'Grace Hopper',
            'email' => 'GRACE@example.com',
        ])->assertSessionHas('profile_status');

        $user->refresh();

        $this->assertSame('Grace Hopper', $user->name);
        $this->assertSame('grace@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_profile_email_must_be_unique(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $this->actingAs($user)->patch(route('account.profile.update'), [
            'name' => $user->name,
            'email' => $otherUser->email,
        ])->assertSessionHasErrors(['email'], errorBag: 'profile');

        $this->assertSame($user->email, $user->fresh()->email);
    }

    public function test_user_can_change_their_password_with_the_current_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('current-password'),
        ]);

        $this->actingAs($user)->patch(route('account.password.update'), [
            'current_password' => 'current-password',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ])->assertSessionHas('password_status');

        $this->assertTrue(Hash::check('new-secure-password', $user->fresh()->password));
    }

    public function test_password_change_rejects_an_incorrect_current_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('current-password'),
        ]);

        $this->actingAs($user)->patch(route('account.password.update'), [
            'current_password' => 'incorrect-password',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ])->assertSessionHasErrors(['current_password'], errorBag: 'password');

        $this->assertTrue(Hash::check('current-password', $user->fresh()->password));
    }

    public function test_social_user_can_set_a_password_without_a_previous_local_password(): void
    {
        $user = User::factory()->create([
            'auth_type' => 'github',
            'password' => Hash::make('unknown-generated-password'),
            'password_set_at' => null,
        ]);

        $this->actingAs($user)->get(route('account.index'))
            ->assertSuccessful()
            ->assertSee('Set a password here to also enable email and password sign-in.')
            ->assertDontSee('Current password');

        $this->actingAs($user)->patch(route('account.password.update'), [
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ])->assertSessionHas('password_status');

        $user = $user->fresh();
        $this->assertTrue(Hash::check('new-secure-password', $user->password));
        $this->assertTrue($user->hasLocalPassword());
        $this->assertSame('github', $user->auth_type);

        $this->actingAs($user)->get(route('account.index'))
            ->assertSuccessful()
            ->assertSee('Current password')
            ->assertDontSee('Set a password here to also enable email and password sign-in.');
    }

    public function test_social_user_must_supply_the_current_password_after_local_password_setup(): void
    {
        $user = User::factory()->create([
            'auth_type' => 'github',
            'password' => Hash::make('current-local-password'),
            'password_set_at' => now(),
        ]);

        $this->actingAs($user)->patch(route('account.password.update'), [
            'password' => 'replacement-password',
            'password_confirmation' => 'replacement-password',
        ])->assertSessionHasErrors(['current_password'], errorBag: 'password');

        $this->assertTrue(Hash::check('current-local-password', $user->fresh()->password));

        $this->actingAs($user)->patch(route('account.password.update'), [
            'current_password' => 'current-local-password',
            'password' => 'replacement-password',
            'password_confirmation' => 'replacement-password',
        ])->assertSessionHas('password_status');

        $this->assertTrue(Hash::check('replacement-password', $user->fresh()->password));
    }

    public function test_password_reset_establishes_a_local_password_for_a_social_only_account(): void
    {
        $user = User::factory()->create([
            'auth_type' => 'gitlab',
            'password_set_at' => null,
        ]);
        $token = Password::broker()->createToken($user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'reset-local-password',
            'password_confirmation' => 'reset-local-password',
        ])->assertRedirect(route('login'));

        $user = $user->fresh();
        $this->assertTrue(Hash::check('reset-local-password', $user->password));
        $this->assertTrue($user->hasLocalPassword());
        $this->assertSame('gitlab', $user->auth_type);
    }

    public function test_local_user_can_disconnect_a_linked_social_account(): void
    {
        $user = User::factory()->create([
            'github_id' => 'github-account-id',
            'auth_type' => 'github',
        ]);

        $this->actingAs($user)->get(route('account.index'))
            ->assertSuccessful()
            ->assertSee('Connected accounts')
            ->assertSee(route('account.social.destroy', 'github'));
        $this->delete(route('account.social.destroy', 'github'))
            ->assertSessionHas('social_status', 'GitHub disconnected.');

        $user->refresh();
        $this->assertNull($user->github_id);
        $this->assertNull($user->auth_type);
        $this->assertTrue($user->hasLocalPassword());
    }

    public function test_social_only_user_cannot_disconnect_their_last_sign_in_method(): void
    {
        $user = User::factory()->create([
            'password_set_at' => null,
            'github_id' => 'only-github-account',
            'auth_type' => 'github',
        ]);

        $this->actingAs($user)->get(route('account.index'))
            ->assertSuccessful()
            ->assertSee('Set a local password before disconnecting your only sign-in method.')
            ->assertDontSee(route('account.social.destroy', 'github'));
        $this->delete(route('account.social.destroy', 'github'))
            ->assertSessionHas('social_error', 'Set a local password before disconnecting your only sign-in method.');

        $this->assertSame('only-github-account', $user->fresh()->github_id);
        $this->assertSame('github', $user->fresh()->auth_type);
    }

    public function test_social_only_user_can_disconnect_one_of_multiple_providers_with_a_fallback(): void
    {
        $user = User::factory()->create([
            'password_set_at' => null,
            'github_id' => 'github-account',
            'gitlab_id' => 'gitlab-account',
            'auth_type' => 'github',
        ]);

        $this->actingAs($user)->delete(route('account.social.destroy', 'github'))
            ->assertSessionHas('social_status', 'GitHub disconnected.');

        $user->refresh();
        $this->assertNull($user->github_id);
        $this->assertSame('gitlab-account', $user->gitlab_id);
        $this->assertSame('gitlab', $user->auth_type);
        $this->assertFalse($user->hasLocalPassword());
    }

    public function test_disconnecting_an_unlinked_or_unknown_provider_is_safe(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->delete(route('account.social.destroy', 'github'))
            ->assertSessionHas('social_status', 'That social account is not connected.');
        $this->delete('/account/social/unknown')->assertNotFound();
    }
}
