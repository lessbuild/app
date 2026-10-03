<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Enums\AccountRole;
use App\Events\Users\PasswordChanged;
use App\Models\Account;
use App\Models\AccountInvitation;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Tests\TestCase;

final class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Signing up creates the person and an account they own, signs them in and asks them to verify their email; the
     * app's API sends them to the verification page until they do.
     */
    public function test_sign_up_creates_the_user_and_an_owned_account_then_asks_for_verification(): void
    {
        Notification::fake();

        $this->postJson('/api/app/auth/register', [
            'name' => 'Ada Lovelace', 'email' => 'ada@example.com',
            'password' => 'correct horse battery staple', 'password_confirmation' => 'correct horse battery staple',
        ])->assertCreated();

        $user = User::query()->where('email', 'ada@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $account = $user->currentAccount;
        $this->assertNotNull($account);
        $this->assertSame(AccountRole::Owner, $account->roleOf($user));
        $this->assertSame('Ada’s account', $account->name);
        Notification::assertSentTo($user, VerifyEmail::class);

        $this->getJson('/api/app/shell')->assertStatus(409)->assertJsonPath('redirect', '/email/verify');
    }

    /**
     * Signing in checks the password; signed in, the app's API answers.
     */
    public function test_verified_users_sign_in(): void
    {
        $user = User::factory()->create(['password' => 'secret-password-123']);
        Account::factory()->withMember($user)->create(['name' => 'Acme']);

        $this->postJson('/api/app/auth/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertGuest();

        $this->postJson('/api/app/auth/login', ['email' => $user->email, 'password' => 'secret-password-123'])->assertOk()->assertJsonPath('two_factor', false);
        $this->getJson('/api/app/shell')->assertOk()->assertJsonPath('account.name', 'Acme');
    }

    /**
     * Guests get a 401 from the app's API, so the app sends them to sign in.
     */
    public function test_guests_are_refused(): void
    {
        $this->getJson('/api/app/shell')->assertUnauthorized();
    }

    /**
     * The verification link in the email verifies the address and goes to the dashboard.
     */
    public function test_the_email_verification_link_verifies_the_account(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);

        $this->actingAs($user)->get($url)->assertRedirect('/dashboard?verified=1');
        $this->assertTrue($user->refresh()->hasVerifiedEmail());
    }

    /**
     * With two-factor on, the password alone doesn't sign in: the challenge takes a code or a recovery code.
     */
    public function test_users_with_two_factor_enabled_must_pass_the_challenge(): void
    {
        $secret = app(TwoFactorAuthenticationProvider::class)->generateSecretKey();
        $user = User::factory()->create(['password' => 'secret-password-123']);
        $user->forceFill([
            'two_factor_secret' => encrypt($secret),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->postJson('/api/app/auth/login', ['email' => $user->email, 'password' => 'secret-password-123'])->assertOk()->assertJsonPath('two_factor', true);
        $this->assertGuest();

        $this->postJson('/api/app/auth/two-factor-challenge', ['code' => '000000'])->assertUnprocessable();
        $this->assertGuest();

        $this->postJson('/api/app/auth/two-factor-challenge', ['recovery_code' => 'recovery-code-1'])->assertNoContent();
        $this->assertAuthenticatedAs($user);
    }

    /**
     * Passkey sign-in starts with options as JSON.
     */
    public function test_passkey_sign_in_options_are_issued_as_json(): void
    {
        $this->getJson('/api/app/auth/passkeys/login/options')->assertOk()->assertJsonStructure(['options' => ['challenge']]);
    }

    /**
     * Changing the password needs the current one, and is recorded.
     */
    public function test_changing_password_requires_the_current_password_and_is_recorded(): void
    {
        Event::fake([PasswordChanged::class]);
        $user = User::factory()->create(['password' => 'old-password-123']);

        $this->actingAs($user)->putJson('/api/app/auth/user/password', [
            'current_password' => 'nope', 'password' => 'new-password-456', 'password_confirmation' => 'new-password-456',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->actingAs($user)->putJson('/api/app/auth/user/password', [
            'current_password' => 'old-password-123', 'password' => 'new-password-456', 'password_confirmation' => 'new-password-456',
        ])->assertOk();

        Event::assertDispatched(PasswordChanged::class);
    }

    /**
     * The sign-in page's options: sign-up open or not, the invited address for a valid access invitation, the social
     * providers set up here, and the message the last round trip left.
     */
    public function test_sign_in_options(): void
    {
        $this->getJson('/api/app/auth/options')->assertOk()->assertJsonStructure(['registrationOpen', 'invitedEmail', 'socialProviders', 'error', 'status']);
        $this->withSession(['status' => 'You were signed out after 30 minutes without activity.'])
            ->getJson('/api/app/auth/options')->assertJsonPath('status', 'You were signed out after 30 minutes without activity.');
    }

    /**
     * An invitation shows who it's from and for which account; accepting it joins the account. Used, revoked or unknown
     * invitations are null.
     */
    public function test_invitations(): void
    {
        $account = Account::factory()->create(['name' => 'Acme']);
        $invitee = User::factory()->create(['email' => 'grace@example.com']);
        $token = str_repeat('a', 40);
        $invitation = new AccountInvitation;
        $invitation->forceFill([
            'account_id' => $account->id, 'email' => 'grace@example.com', 'role' => AccountRole::Member,
            'token_hash' => AccountInvitation::hashToken($token), 'expires_at' => now()->addDay(),
        ])->save();

        $this->getJson("/api/app/invitations/{$token}")->assertOk()->assertJsonPath('invitation.accountName', 'Acme')->assertJsonPath('signedInAs', null);
        $this->getJson('/api/app/invitations/'.str_repeat('b', 40))->assertOk()->assertJsonPath('invitation', null);

        $this->actingAs($invitee)->postJson("/api/app/invitations/{$token}")->assertOk()->assertJsonPath('redirect', '/dashboard');
        $this->assertSame(AccountRole::Member, $account->roleOf($invitee));
        $this->getJson("/api/app/invitations/{$token}")->assertJsonPath('invitation', null);
    }

    /**
     * Single sign-on only starts for an address whose account uses it.
     */
    public function test_single_sign_on_needs_an_account_that_uses_it(): void
    {
        $this->postJson('/api/app/auth/sso', ['email' => 'nobody@example.com'])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    /**
     * While sign-up is closed, people ask for access and get a receipt.
     */
    public function test_access_requests(): void
    {
        Notification::fake();
        $this->getJson('/api/app/access-requests')->assertOk()->assertJsonStructure(['registrationOpen', 'teamSizes']);
        $this->postJson('/api/app/access-requests', ['name' => 'Ada', 'email' => 'ada@example.com', 'use_case' => ''])->assertUnprocessable()->assertJsonValidationErrors('use_case');
        $this->postJson('/api/app/access-requests', ['name' => 'Ada', 'email' => 'ada@example.com', 'team_size' => '2-5', 'use_case' => 'Deploying our shop.'])->assertCreated();
    }
}
