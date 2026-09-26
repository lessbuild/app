<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

final class SettingsPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_need_a_verified_signed_in_user(): void
    {
        $this->get('/settings/profile')->assertRedirect('/login');
        $this->actingAs(User::factory()->unverified()->create())->get('/settings/profile')->assertRedirect('/email/verify');
    }

    public function test_the_profile_page_shows_and_saves_the_profile(): void
    {
        $user = User::factory()->create(['name' => 'Ada Lovelace']);

        $this->actingAs($user)->get('/settings')->assertRedirect('/settings/profile');
        $this->actingAs($user)->get('/settings/profile')
            ->assertOk()
            ->assertSee('value="Ada Lovelace"', false)
            ->assertSee('aria-current="page"', false);

        $this->actingAs($user)->from('/settings/profile')->put('/user/profile-information', ['name' => 'Ada King', 'email' => $user->email])
            ->assertRedirect('/settings/profile')
            ->assertSessionHas('status', Fortify::PROFILE_INFORMATION_UPDATED);
        $this->assertSame('Ada King', $user->refresh()->name);
    }

    public function test_the_security_page_asks_for_a_recent_password_confirmation(): void
    {
        $user = User::factory()->create(['password' => 'secret-password-123']);

        $this->actingAs($user)->get('/settings/security')->assertRedirect('/user/confirm-password');
        $this->actingAs($user)->get('/user/confirm-password')->assertOk()->assertSee('name="password"', false)->assertDontSee('data-passkey-login', false);

        $this->actingAs($user)->post('/user/confirm-password', ['password' => 'secret-password-123'])->assertRedirect('/settings/security');
        $this->actingAs($user)->get('/settings/security')->assertOk()->assertSee(__('Set up an authenticator app'));
    }

    public function test_passkey_owners_can_confirm_with_a_passkey_and_see_their_passkeys(): void
    {
        $user = User::factory()->create();
        $user->passkeys()->forceCreate(['name' => 'Work laptop', 'credential_id' => 'cred-1', 'credential' => []]);

        $this->actingAs($user)->get('/user/confirm-password')->assertOk()->assertSee('data-passkey-login', false)->assertSee(route('passkey.confirm'), false);

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->get('/settings/security')
            ->assertOk()
            ->assertSee('Work laptop')
            ->assertSee('data-passkey-registration', false);
    }

    public function test_two_factor_setup_shows_the_recovery_codes_once(): void
    {
        $user = User::factory()->create();
        $confirmed = ['auth.password_confirmed_at' => time()];

        $this->actingAs($user)->withSession($confirmed)->from('/settings/security')->post('/user/two-factor-authentication')->assertRedirect('/settings/security');
        $this->actingAs($user)->withSession($confirmed)->get('/settings/security')
            ->assertOk()
            ->assertSee('data-two-factor-qr-code', false)
            ->assertSee(__('Confirm and turn on'));

        $secret = (string) Fortify::currentEncrypter()->decrypt((string) $user->refresh()->two_factor_secret);
        $code = app(Google2FA::class)->getCurrentOtp($secret);
        $this->actingAs($user)->withSession($confirmed)->from('/settings/security')->post('/user/confirmed-two-factor-authentication', ['code' => $code])
            ->assertRedirect('/settings/security');

        $recoveryCode = (string) $user->refresh()->recoveryCodes()[0];
        $this->actingAs($user)->withSession([...$confirmed, 'status' => Fortify::TWO_FACTOR_AUTHENTICATION_CONFIRMED])->get('/settings/security')
            ->assertOk()
            ->assertSee($recoveryCode);
        $this->actingAs($user)->withSession($confirmed)->get('/settings/security')
            ->assertOk()
            ->assertDontSee($recoveryCode)
            ->assertSee(__('Turn off two-factor authentication'));
    }
}
