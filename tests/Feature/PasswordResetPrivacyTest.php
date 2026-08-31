<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\AccountSecurityNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_and_reset_password_pages_render_their_forms(): void
    {
        $this->get(route('password.request'))
            ->assertSuccessful()
            ->assertSee('Reset your password')
            ->assertSee(route('password.email'))
            ->assertSee('Send reset link');

        $this->get(route('password.reset', [
            'token' => 'sample-reset-token',
            'email' => 'person@example.test',
        ]))
            ->assertSuccessful()
            ->assertSee('Choose a new password')
            ->assertSee('sample-reset-token')
            ->assertSee('person@example.test')
            ->assertSee(route('password.update'));
    }

    public function test_known_and_unknown_emails_receive_the_same_public_reset_link_response(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'known-reset@example.test']);
        $message = 'If an account exists for that email, a password reset link has been sent.';

        $this->post(route('password.email'), ['email' => $user->email])
            ->assertRedirect()
            ->assertSessionHas('status', $message)
            ->assertSessionDoesntHaveErrors();

        $this->post(route('password.email'), ['email' => 'unknown-reset@example.test'])
            ->assertRedirect()
            ->assertSessionHas('status', $message)
            ->assertSessionDoesntHaveErrors();

        Notification::assertSentTo($user, ResetPassword::class);
        Notification::assertCount(1);
    }

    public function test_malformed_email_is_rejected_without_attempting_delivery(): void
    {
        Notification::fake();

        $this->post(route('password.email'), ['email' => 'not-an-email'])
            ->assertRedirect()
            ->assertSessionHasErrors('email');

        Notification::assertNothingSent();
    }

    public function test_invalid_reset_tokens_do_not_reveal_whether_the_email_exists(): void
    {
        $user = User::factory()->create([
            'email' => 'known-token@example.test',
            'password' => Hash::make('original-password'),
        ]);
        $payload = [
            'token' => 'invalid-reset-token',
            'password' => 'replacement-password',
            'password_confirmation' => 'replacement-password',
        ];

        $known = $this->post(route('password.update'), [
            ...$payload,
            'email' => $user->email,
        ])->assertRedirect()->assertSessionHasErrors('email');
        $knownMessage = $known->getSession()->get('errors')->get('email');

        $unknown = $this->post(route('password.update'), [
            ...$payload,
            'email' => 'unknown-token@example.test',
        ])->assertRedirect()->assertSessionHasErrors('email');
        $unknownMessage = $unknown->getSession()->get('errors')->get('email');

        $this->assertSame(['This password reset link is invalid or has expired.'], $knownMessage);
        $this->assertSame($knownMessage, $unknownMessage);
        $this->assertTrue(Hash::check('original-password', $user->fresh()->password));
    }

    public function test_valid_reset_token_still_updates_the_password_and_establishes_local_login(): void
    {
        $user = User::factory()->create([
            'auth_type' => 'github',
            'password_set_at' => null,
        ]);
        $token = Password::broker()->createToken($user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'replacement-password',
            'password_confirmation' => 'replacement-password',
        ])->assertRedirect(route('login'))
            ->assertSessionHas('status', __(Password::PASSWORD_RESET));

        $user->refresh();
        $this->assertTrue(Hash::check('replacement-password', $user->password));
        $this->assertTrue($user->hasLocalPassword());
        $this->assertDatabaseHas('events', [
            'user_id' => $user->id,
            'category' => 'account',
            'event' => 'Account password was reset.',
            'parentable_type' => User::class,
            'parentable_id' => $user->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'type' => AccountSecurityNotification::class,
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data->category' => 'account',
            'data->message' => 'Account password was reset.',
            'data->status' => 'info',
        ]);
    }
}
