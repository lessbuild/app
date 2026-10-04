<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

final class SettingsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Settings need a signed-in person with a verified email address.
     */
    public function test_settings_need_a_verified_signed_in_user(): void
    {
        $this->getJson('/api/app/settings/profile')->assertUnauthorized();
        $this->actingAs(User::factory()->unverified()->create())->getJson('/api/app/settings/profile')->assertStatus(409)->assertJsonPath('redirect', '/email/verify');
    }

    /**
     * The profile shows and saves the name, email address and language.
     */
    public function test_the_profile_shows_and_saves_the_profile(): void
    {
        $user = User::factory()->create(['name' => 'Ada Lovelace']);

        $this->actingAs($user)->getJson('/api/app/settings/profile')->assertOk()->assertJsonPath('name', 'Ada Lovelace')->assertJsonPath('locales.es', 'Español');
        $this->actingAs($user)->putJson('/api/app/auth/user/profile-information', ['name' => 'Ada King', 'email' => $user->email])->assertOk();
        $this->assertSame('Ada King', $user->refresh()->name);
    }

    /**
     * The security settings need a recent confirmation of who the person is.
     */
    public function test_the_security_settings_ask_for_a_recent_password_confirmation(): void
    {
        $user = User::factory()->create(['password' => 'secret-password-123']);

        $this->actingAs($user)->getJson('/api/app/settings/security')->assertStatus(423);
        $this->actingAs($user)->postJson('/api/app/auth/user/confirm-password', ['password' => 'secret-password-123'])->assertCreated();
        $this->actingAs($user)->getJson('/api/app/settings/security')->assertOk()->assertJsonPath('security.twoFactor', 'off')->assertJsonPath('security.hasPassword', true);
    }

    /**
     * Passkey owners see their passkeys and can confirm it's them with one.
     */
    public function test_passkey_owners_see_their_passkeys(): void
    {
        $user = User::factory()->create();
        $user->passkeys()->forceCreate(['name' => 'Work laptop', 'credential_id' => 'cred-1', 'credential' => []]);

        $this->actingAs($user)->getJson('/api/app/auth/me')->assertOk()->assertJsonPath('hasPasskeys', true);
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->getJson('/api/app/settings/security')
            ->assertOk()->assertJsonPath('security.passkeys.0.name', 'Work laptop');
    }

    /**
     * Two-factor setup shows the QR code until it's confirmed; the recovery codes come from Fortify afterwards.
     */
    public function test_two_factor_setup(): void
    {
        $user = User::factory()->create();
        $confirmed = ['auth.password_confirmed_at' => time()];

        $this->actingAs($user)->withSession($confirmed)->postJson('/api/app/auth/user/two-factor-authentication')->assertOk();
        $pending = $this->actingAs($user)->withSession($confirmed)->getJson('/api/app/settings/security')->assertOk()->assertJsonPath('security.twoFactor', 'pending');
        $this->assertStringContainsString('<svg', (string) $pending->json('security.pendingQrCodeSvg'));

        $secret = (string) Fortify::currentEncrypter()->decrypt((string) $user->refresh()->two_factor_secret);
        $code = app(Google2FA::class)->getCurrentOtp($secret);
        $this->actingAs($user)->withSession($confirmed)->postJson('/api/app/auth/user/confirmed-two-factor-authentication', ['code' => $code])->assertOk();

        $recoveryCode = (string) $user->refresh()->recoveryCodes()[0];
        $this->actingAs($user)->withSession($confirmed)->getJson('/api/app/settings/security')->assertOk()->assertJsonPath('security.twoFactor', 'on')->assertJsonPath('security.recoveryCodes', [])->assertDontSee($recoveryCode);
        $this->assertContains($recoveryCode, (array) $this->actingAs($user)->withSession($confirmed)->getJson('/api/app/auth/user/two-factor-recovery-codes')->assertOk()->json());
    }
}
