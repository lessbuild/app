<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TwoFactorAuthentication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_enable_two_factor_and_receives_hashed_recovery_codes(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('account.two-factor.enable'), [
            'current_password' => 'password',
        ])->assertRedirect();

        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertNotSame($user->two_factor_secret, DB::table('users')->where('id', $user->id)->value('two_factor_secret'));

        $response = $this->actingAs($user)->post(route('account.two-factor.confirm'), [
            'code' => $this->totp($user->two_factor_secret),
        ])->assertRedirect()->assertSessionHas('two_factor_recovery_codes');

        $recoveryCodes = $response->getSession()->get('two_factor_recovery_codes');
        $user->refresh();
        $this->assertTrue($user->twoFactorEnabled());
        $this->assertCount(8, $recoveryCodes);
        $this->assertCount(8, $user->two_factor_recovery_codes);
        $this->assertNotContains($recoveryCodes[0], $user->two_factor_recovery_codes);
        $this->assertArrayNotHasKey('two_factor_secret', $user->toArray());
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $user->toArray());
        $this->assertDatabaseHas('events', [
            'user_id' => $user->id,
            'category' => 'account',
            'event' => 'Two-factor authentication was enabled.',
        ]);
    }

    public function test_confirmation_rejects_an_invalid_code_without_changing_pending_setup(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('account.two-factor.enable'), [
            'current_password' => 'password',
        ])->assertRedirect();
        $user->refresh();
        $secret = $user->two_factor_secret;

        $this->actingAs($user)->post(route('account.two-factor.confirm'), [
            'code' => '000000',
        ])->assertSessionHasErrors(['code'], errorBag: 'twoFactor');

        $user->refresh();
        $this->assertSame($secret, $user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertNull($user->two_factor_recovery_codes);
    }

    public function test_confirmation_requires_a_pending_secret(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('account.two-factor.confirm'), [
            'code' => '000000',
        ])->assertSessionHasErrors(['code'], errorBag: 'twoFactor');

        $this->assertDatabaseMissing('events', [
            'user_id' => $user->id,
            'event' => 'Two-factor authentication was enabled.',
        ]);
    }

    public function test_enabled_two_factor_rejects_setup_before_password_validation(): void
    {
        $user = $this->enabledUser();
        $secret = $user->two_factor_secret;
        $recoveryCodes = $user->two_factor_recovery_codes;

        $this->actingAs($user)->post(route('account.two-factor.enable'))->assertStatus(422);

        $user->refresh();
        $this->assertSame($secret, $user->two_factor_secret);
        $this->assertSame($recoveryCodes, $user->two_factor_recovery_codes);
    }

    public function test_social_only_user_can_begin_two_factor_setup_without_a_password(): void
    {
        $user = User::factory()->create([
            'password_set_at' => null,
            'auth_type' => 'github',
        ]);

        $this->actingAs($user)->post(route('account.two-factor.enable'), [])
            ->assertSessionHas('two_factor_status', 'Enter a code from your authenticator app to finish setup.');

        $this->assertNotNull($user->fresh()->two_factor_secret);
    }

    public function test_pending_two_factor_setup_can_be_cancelled(): void
    {
        $user = User::factory()->create();
        $user->forceFill([
            'two_factor_secret' => app(TwoFactorAuthentication::class)->generateSecret(),
            'two_factor_recovery_codes' => ['stale-recovery-hash'],
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->actingAs($user)->delete(route('account.two-factor.cancel'))
            ->assertSessionHas('two_factor_status', 'Two-factor setup cancelled.');

        $user->refresh();
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);
    }

    public function test_enabled_two_factor_cannot_be_cancelled(): void
    {
        $user = $this->enabledUser();
        $secret = $user->two_factor_secret;

        $this->actingAs($user)->delete(route('account.two-factor.cancel'))->assertStatus(422);

        $this->assertSame($secret, $user->fresh()->two_factor_secret);
    }

    public function test_enabled_two_factor_is_required_after_password_login(): void
    {
        $user = $this->enabledUser();

        $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('two-factor.login'))
            ->assertSessionHas('two_factor_login_user_id', $user->id);
        $this->assertGuest();

        $this->post(route('two-factor.login'), ['code' => '000000'])
            ->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->post(route('two-factor.login'), ['code' => $this->totp($user->two_factor_secret)])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('sign_in_events', 1);
    }

    public function test_recovery_codes_are_single_use(): void
    {
        $user = $this->enabledUser();
        $recoveryCode = 'ABCD-EF12-3456';
        $user->forceFill([
            'two_factor_recovery_codes' => app(TwoFactorAuthentication::class)->recoveryCodeHashes([$recoveryCode]),
        ])->save();

        $this->withSession(['two_factor_login_user_id' => $user->id, 'two_factor_login_method' => 'password'])
            ->post(route('two-factor.login'), ['code' => strtolower($recoveryCode)])
            ->assertRedirect(route('dashboard'));
        $this->assertSame([], $user->fresh()->two_factor_recovery_codes);

        auth()->logout();
        $this->withSession(['two_factor_login_user_id' => $user->id, 'two_factor_login_method' => 'password'])
            ->post(route('two-factor.login'), ['code' => $recoveryCode])
            ->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_two_stale_user_instances_cannot_consume_the_same_recovery_code(): void
    {
        $user = $this->enabledUser();
        $service = app(TwoFactorAuthentication::class);
        $recoveryCode = 'ABCD-EFAB-CDEF';
        $remainingCode = 'CDEF-ABCD-EFAB';
        $hashes = $service->recoveryCodeHashes([$recoveryCode, $remainingCode]);
        $user->forceFill(['two_factor_recovery_codes' => $hashes])->save();
        $firstRequestUser = $user->fresh();
        $secondRequestUser = $user->fresh();

        $this->assertTrue($service->verifyUser($firstRequestUser, $recoveryCode));
        $this->assertSame($hashes, $secondRequestUser->two_factor_recovery_codes);
        $this->assertFalse($service->verifyUser($secondRequestUser, strtolower($recoveryCode)));
        $this->assertSame([$hashes[1]], $user->fresh()->two_factor_recovery_codes);
        $this->assertFalse($service->verifyUser($firstRequestUser, $recoveryCode));
        $this->assertTrue($service->verifyUser($secondRequestUser, $remainingCode));
        $this->assertSame([], $user->fresh()->two_factor_recovery_codes);
    }

    public function test_recovery_code_verification_can_leave_the_loaded_code_unconsumed(): void
    {
        $user = $this->enabledUser();
        $service = app(TwoFactorAuthentication::class);
        $recoveryCode = 'ABCD-EFAB-CDEF';
        $hashes = $service->recoveryCodeHashes([$recoveryCode]);
        $user->forceFill(['two_factor_recovery_codes' => $hashes])->save();

        $this->assertTrue($service->verifyUser($user, $recoveryCode, consumeRecoveryCode: false));
        $this->assertTrue($service->verifyUser($user, strtolower($recoveryCode), consumeRecoveryCode: false));
        $this->assertFalse($service->verifyUser($user, 'FFFF-FFFF-FFFF', consumeRecoveryCode: false));
        $this->assertSame($hashes, $user->fresh()->two_factor_recovery_codes);
        $this->assertTrue($service->verifyUser($user, $recoveryCode));
        $this->assertSame([], $user->fresh()->two_factor_recovery_codes);
    }

    public function test_authenticator_verification_does_not_consume_recovery_codes(): void
    {
        $user = $this->enabledUser();
        $service = app(TwoFactorAuthentication::class);
        $hashes = $user->two_factor_recovery_codes;
        $code = $this->totp($user->two_factor_secret);

        $this->assertTrue($service->verifyUser($user, $code));
        $this->assertTrue($service->verifyUser($user, $code, consumeRecoveryCode: false));
        $this->assertSame($hashes, $user->fresh()->two_factor_recovery_codes);
    }

    public function test_user_can_disable_two_factor_with_password_and_authenticator_code(): void
    {
        $user = $this->enabledUser();

        $this->actingAs($user)->delete(route('account.two-factor.disable'), [
            'current_password' => 'password',
            'code' => $this->totp($user->two_factor_secret),
        ])->assertRedirect();

        $user->refresh();
        $this->assertFalse($user->twoFactorEnabled());
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
    }

    public function test_disable_rejects_an_invalid_code_without_changing_credentials(): void
    {
        $user = $this->enabledUser();
        $secret = $user->two_factor_secret;
        $recoveryCodes = $user->two_factor_recovery_codes;

        $this->actingAs($user)->delete(route('account.two-factor.disable'), [
            'current_password' => 'password',
            'code' => '000000',
        ])->assertSessionHasErrors(['code'], errorBag: 'twoFactor');

        $user->refresh();
        $this->assertSame($secret, $user->two_factor_secret);
        $this->assertSame($recoveryCodes, $user->two_factor_recovery_codes);
        $this->assertNotNull($user->two_factor_confirmed_at);
    }

    public function test_social_only_user_can_disable_two_factor_with_a_recovery_code(): void
    {
        $user = User::factory()->create([
            'password_set_at' => null,
            'auth_type' => 'github',
        ]);
        $service = app(TwoFactorAuthentication::class);
        $recoveryCode = 'ABCD-EFAB-CDEF';
        $user->forceFill([
            'two_factor_secret' => $service->generateSecret(),
            'two_factor_recovery_codes' => $service->recoveryCodeHashes([$recoveryCode]),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->actingAs($user)->delete(route('account.two-factor.disable'), [
            'code' => strtolower($recoveryCode),
        ])->assertSessionHas('two_factor_status', 'Two-factor authentication disabled.');

        $user->refresh();
        $this->assertFalse($user->twoFactorEnabled());
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
    }

    public function test_enabled_user_can_regenerate_recovery_codes_without_consuming_the_authenticator_code(): void
    {
        $user = $this->enabledUser();
        $oldRecoveryCodes = $user->two_factor_recovery_codes;
        $totp = $this->totp($user->two_factor_secret);

        $response = $this->actingAs($user)->post(route('account.two-factor.recovery-codes'), [
            'current_password' => 'password',
            'code' => $totp,
        ])->assertSessionHas('two_factor_status', 'New recovery codes created. Previous codes no longer work.');

        $newRecoveryCodes = $response->getSession()->get('two_factor_recovery_codes');
        $user->refresh();
        $this->assertCount(8, $newRecoveryCodes);
        $this->assertCount(8, $user->two_factor_recovery_codes);
        $this->assertNotSame($oldRecoveryCodes, $user->two_factor_recovery_codes);
        $this->assertNotContains($newRecoveryCodes[0], $user->two_factor_recovery_codes);
        $this->assertDatabaseHas('events', [
            'user_id' => $user->id,
            'category' => 'account',
            'event' => 'Two-factor recovery codes were regenerated.',
        ]);
    }

    public function test_recovery_code_regeneration_rejects_an_invalid_code_without_replacing_hashes(): void
    {
        $user = $this->enabledUser();
        $oldRecoveryCodes = $user->two_factor_recovery_codes;

        $this->actingAs($user)->post(route('account.two-factor.recovery-codes'), [
            'current_password' => 'password',
            'code' => '000000',
        ])->assertSessionHasErrors(['code'], errorBag: 'twoFactor');

        $this->assertSame($oldRecoveryCodes, $user->fresh()->two_factor_recovery_codes);
    }

    public function test_disabled_two_factor_rejects_recovery_code_regeneration_before_validation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('account.two-factor.recovery-codes'))->assertStatus(422);

        $this->assertDatabaseMissing('events', [
            'user_id' => $user->id,
            'event' => 'Two-factor recovery codes were regenerated.',
        ]);
    }

    public function test_social_only_user_can_regenerate_recovery_codes_without_a_password(): void
    {
        $user = User::factory()->create([
            'password_set_at' => null,
            'auth_type' => 'github',
        ]);
        $service = app(TwoFactorAuthentication::class);
        $user->forceFill([
            'two_factor_secret' => $service->generateSecret(),
            'two_factor_recovery_codes' => $service->recoveryCodeHashes($service->generateRecoveryCodes()),
            'two_factor_confirmed_at' => now(),
        ])->save();
        $user->refresh();

        $this->actingAs($user)->post(route('account.two-factor.recovery-codes'), [
            'code' => $this->totp($user->two_factor_secret),
        ])->assertSessionHas('two_factor_recovery_codes');

        $this->assertCount(8, $user->fresh()->two_factor_recovery_codes);
    }

    private function enabledUser(): User
    {
        $user = User::factory()->create();
        $service = app(TwoFactorAuthentication::class);
        $user->forceFill([
            'two_factor_secret' => $service->generateSecret(),
            'two_factor_recovery_codes' => $service->recoveryCodeHashes($service->generateRecoveryCodes()),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user->refresh();
    }

    private function totp(string $secret, ?int $timestamp = null): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($secret) as $character) {
            $bits .= str_pad(decbin((int) strpos($alphabet, $character)), 5, '0', STR_PAD_LEFT);
        }
        $key = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $key .= chr(bindec($byte));
            }
        }
        $counter = intdiv($timestamp ?? time(), 30);
        $hash = hash_hmac('sha1', pack('N2', 0, $counter), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($binary % 1_000_000), 6, '0', STR_PAD_LEFT);
    }
}
