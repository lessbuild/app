<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domain\Accounts\Enums\AccountRole;
use App\Domain\Accounts\Models\Account;
use App\Domain\Identity\Events\PasswordChanged;
use App\Domain\Identity\Models\User;
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

    public function test_auth_pages_render_with_the_signal_layout(): void
    {
        foreach (['/login', '/register', '/forgot-password'] as $path) {
            $this->get($path)->assertOk()->assertSee('ui-btn', false);
        }
        $this->get('/login')->assertSee('data-passkey-login', false);
    }

    public function test_registration_creates_the_user_and_an_owned_account_then_asks_for_verification(): void
    {
        Notification::fake();

        $this->post('/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'correct horse battery staple',
            'password_confirmation' => 'correct horse battery staple',
        ])->assertRedirect('/dashboard');

        $user = User::query()->where('email', 'ada@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $account = $user->currentAccount;
        $this->assertNotNull($account);
        $this->assertSame(AccountRole::Owner, $account->roleOf($user));
        $this->assertSame('Ada’s account', $account->name);
        Notification::assertSentTo($user, VerifyEmail::class);

        $this->get('/dashboard')->assertRedirect('/email/verify');
    }

    public function test_verified_users_sign_in_and_reach_the_dashboard(): void
    {
        $user = User::factory()->create(['password' => 'secret-password-123']);
        Account::factory()->withMember($user)->create(['name' => 'Acme']);

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('/login', ['email' => $user->email, 'password' => 'secret-password-123'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk()->assertSee('Acme');
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get('/')->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_email_verification_link_verifies_the_account(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);

        $this->actingAs($user)->get($url)->assertRedirect('/dashboard?verified=1');
        $this->assertTrue($user->refresh()->hasVerifiedEmail());
    }

    public function test_users_with_two_factor_enabled_must_pass_the_challenge(): void
    {
        $provider = app(TwoFactorAuthenticationProvider::class);
        $secret = $provider->generateSecretKey();
        $user = User::factory()->create(['password' => 'secret-password-123']);
        $user->forceFill([
            'two_factor_secret' => encrypt($secret),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->post('/login', ['email' => $user->email, 'password' => 'secret-password-123'])->assertRedirect('/two-factor-challenge');
        $this->assertGuest();

        $this->post('/two-factor-challenge', ['code' => '000000'])->assertRedirect('/two-factor-challenge');
        $this->assertGuest();

        $this->post('/two-factor-challenge', ['recovery_code' => 'recovery-code-1'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_passkey_sign_in_options_are_issued_as_json(): void
    {
        $this->getJson('/passkeys/login/options')->assertOk()->assertJsonStructure(['options' => ['challenge']]);
    }

    public function test_changing_password_requires_the_current_password_and_is_recorded(): void
    {
        Event::fake([PasswordChanged::class]);
        $user = User::factory()->create(['password' => 'old-password-123']);

        $this->actingAs($user)->put('/user/password', [
            'current_password' => 'nope',
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])->assertSessionHasErrorsIn('updatePassword', 'current_password');

        $this->actingAs($user)->put('/user/password', [
            'current_password' => 'old-password-123',
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])->assertSessionHasNoErrors();

        Event::assertDispatched(PasswordChanged::class);
    }
}
