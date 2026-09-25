<?php

namespace Tests\Feature\Core;

use App\Core\Models\PlatformUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PlatformAuthenticationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        Schema::connection('core')->create('users', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('email_normalized')->nullable()->index();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->timestamp('password_set_at')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->json('preferences')->nullable();
            $table->string('status', 24)->default('active');
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::connection('core')->create('passkeys', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('user_id', 26)->index();
            $table->string('name');
            $table->string('credential_id')->unique();
            $table->json('credential');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('user_identities', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('user_id', 26)->index();
            $table->string('provider', 48);
            $table->string('provider_user_id', 191);
            $table->string('provider_email')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('status', 24)->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
        Schema::connection('core')->create('platform_auth_sessions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('user_id', 26)->index();
            $table->string('remember_token_hash', 64)->nullable()->index();
            $table->boolean('remembered')->default(false);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();
        });
        Schema::connection('core')->create('platform_sso_tickets', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('token_hash', 64)->unique();
            $table->char('auth_session_id', 26)->index();
            $table->char('user_id', 26)->index();
            $table->string('issuer_origin', 255);
            $table->string('audience_origin', 255);
            $table->text('return_url');
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable()->index();
            $table->timestamp('created_at')->nullable();
        });
        Schema::connection('core')->create('platform_registration_mutexes', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
        });
        DB::connection('core')->table('platform_registration_mutexes')->insert(['id' => 1]);

        Auth::forgetGuards();
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        Schema::connection('core')->dropIfExists('platform_sso_tickets');
        Schema::connection('core')->dropIfExists('platform_auth_sessions');
        Schema::connection('core')->dropIfExists('platform_registration_mutexes');
        Schema::connection('core')->dropIfExists('password_reset_tokens');
        Schema::connection('core')->dropIfExists('user_identities');
        Schema::connection('core')->dropIfExists('passkeys');
        Schema::connection('core')->dropIfExists('users');

        parent::tearDown();
    }

    public function test_platform_authentication_pages_render_with_the_shared_signal_components(): void
    {
        $this->get(route('platform.home'))
            ->assertRedirect(route('platform.login'));

        $this->get(route('platform.login'))
            ->assertOk()
            ->assertSeeText('Sign in')
            ->assertSee('name="remember"', false);

        $this->get(route('platform.password.request'))
            ->assertOk()
            ->assertSeeText('Reset your password');

        $this->withSession(['platform.auth.two_factor_user_id' => (string) Str::ulid()])
            ->get(route('platform.two-factor.create'))
            ->assertOk()
            ->assertSeeText('Verify it’s you');

        $this->get(route('platform.password.reset', [
            'token' => 'sample-reset-token',
            'email' => 'person@example.test',
        ]))
            ->assertOk()
            ->assertSeeText('Choose a new password')
            ->assertSee('value="person@example.test"', false);
    }

    public function test_signed_in_shared_theme_is_rendered_from_core_preferences(): void
    {
        $user = $this->createPlatformUser('theme@example.test', 'correct horse battery staple');
        $user->forceFill(['preferences' => ['theme' => 'dark', 'locale' => 'en']])->save();
        $this->actingAs($user, 'platform');

        $html = Blade::render(<<<'BLADE'
            <x-signal.layouts.core title="Theme preference" :livewire="false">
                <main>Theme preference proof</main>
            </x-signal.layouts.core>
            BLADE);

        $this->assertStringContainsString('data-theme-user-appearance="dark"', $html);
        $this->assertStringContainsString('data-default-appearance="dark"', $html);
        $this->assertStringContainsString('data-theme-preference-url="/__platform/preferences/theme"', $html);
    }

    public function test_signed_in_user_can_update_shared_theme_without_replacing_other_preferences(): void
    {
        $user = $this->createPlatformUser('theme-write@example.test', 'correct horse battery staple');
        $user->forceFill(['preferences' => ['theme' => 'light', 'locale' => 'en']])->save();

        $this->actingAs($user, 'platform')
            ->putJson('/__platform/preferences/theme', ['appearance' => 'dark'])
            ->assertOk()
            ->assertExactJson(['appearance' => 'dark']);

        $this->assertSame([
            'theme' => 'dark',
            'locale' => 'en',
        ], $user->fresh()->preferences);

        $this->putJson('/__platform/preferences/theme', ['appearance' => 'system'])
            ->assertOk()
            ->assertExactJson(['appearance' => 'system']);

        $this->assertSame([
            'theme' => 'system',
            'locale' => 'en',
        ], $user->fresh()->preferences);
    }

    public function test_shared_theme_update_rejects_invalid_appearance_values(): void
    {
        $user = $this->createPlatformUser('invalid-theme@example.test', 'correct horse battery staple');
        $user->forceFill(['preferences' => ['theme' => 'light']])->save();

        $this->actingAs($user, 'platform')
            ->putJson('/__platform/preferences/theme', ['appearance' => 'ultraviolet'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('appearance');

        $this->assertSame(['theme' => 'light'], $user->fresh()->preferences);
    }

    public function test_guest_cannot_update_shared_theme_preferences(): void
    {
        $this->putJson('/__platform/preferences/theme', ['appearance' => 'dark'])
            ->assertUnauthorized();
    }

    public function test_core_login_authenticates_by_the_normalized_platform_email(): void
    {
        $user = $this->createPlatformUser('Person@Example.test', 'correct horse battery staple');

        $response = $this->post(route('platform.login.store'), [
            'email' => '  PERSON@example.test ',
            'password' => 'correct horse battery staple',
        ]);

        $response->assertRedirect(route('core.home'));
        $this->assertAuthenticatedAs($user, 'platform');
    }

    public function test_core_login_rejects_ambiguous_normalized_emails(): void
    {
        $this->createPlatformUser('person@example.test', 'shared password');
        $this->createPlatformUser('PERSON@EXAMPLE.TEST', 'shared password');

        $this->from(route('platform.login'))->followingRedirects()->post(route('platform.login.store'), [
            'email' => 'person@example.test',
            'password' => 'shared password',
        ])
            ->assertOk()
            ->assertSee('id="email-error"', false)
            ->assertSee('aria-describedby="email-error"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertDontSee('value="shared password"', false);

        $this->assertGuest('platform');
    }

    public function test_core_login_requires_and_consumes_an_imported_recovery_code(): void
    {
        $recoveryCode = 'ABCD-1234-EFGH';
        $recoveryHash = hash('sha256', 'ABCD1234EFGH');
        $user = $this->createPlatformUser('secure@example.test', 'correct horse battery staple', [
            'two_factor_secret' => Crypt::encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => Crypt::encrypt(json_encode([$recoveryHash], JSON_THROW_ON_ERROR)),
            'two_factor_confirmed_at' => now(),
        ]);

        $this->post(route('platform.login.store'), [
            'email' => 'secure@example.test',
            'password' => 'correct horse battery staple',
        ])->assertRedirect(route('platform.two-factor.create'));

        $this->assertGuest('platform');
        $this->assertSame($user->getKey(), session('platform.auth.two_factor_user_id'));

        $this->post(route('platform.two-factor.store'), ['code' => $recoveryCode])
            ->assertRedirect(route('core.home'));

        $this->assertAuthenticatedAs($user, 'platform');
        $remainingCodes = json_decode(Crypt::decrypt($user->fresh()->two_factor_recovery_codes), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([], $remainingCodes);
    }

    public function test_core_password_reset_updates_only_the_core_account(): void
    {
        $user = $this->createPlatformUser('reset@example.test', 'old password');
        $authSessionId = (string) Str::ulid();
        DB::connection('core')->table('platform_auth_sessions')->insert([
            'id' => $authSessionId,
            'user_id' => $user->getKey(),
            'remember_token_hash' => null,
            'remembered' => false,
            'revoked_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $token = Password::broker('platform_users')->createToken($user);

        $response = $this->post(route('platform.password.update'), [
            'email' => $user->email,
            'token' => $token,
            'password' => 'a new secure password',
            'password_confirmation' => 'a new secure password',
        ]);

        $response->assertRedirect(route('platform.login'));
        $this->assertTrue(Hash::check('a new secure password', $user->fresh()->password));
        $this->assertFalse(Hash::check('old password', $user->fresh()->password));
        $this->assertNotNull(DB::connection('core')->table('platform_auth_sessions')->where('id', $authSessionId)->value('revoked_at'));
    }

    public function test_platform_redirects_allow_only_configured_origins_or_local_paths(): void
    {
        config(['platform.products.deployer.url' => 'https://deployer.example.test']);

        $this->createPlatformUser('redirect@example.test', 'correct horse battery staple');

        $handoff = $this->post(route('platform.login.store'), [
            'email' => 'redirect@example.test',
            'password' => 'correct horse battery staple',
            'return_to' => 'https://deployer.example.test/projects/01J8AA00000000000000000000',
        ]);
        $handoff->assertOk()
            ->assertSee('action="https://deployer.example.test/__platform/sso/exchange"', false)
            ->assertSeeText('Connecting your session')
            ->assertHeader('Referrer-Policy', 'strict-origin');
        $contentSecurityPolicy = (string) $handoff->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("form-action 'self' http://localhost", $contentSecurityPolicy);
        $this->assertStringContainsString('https://deployer.example.test', $contentSecurityPolicy);
        $this->assertDatabaseHas('platform_sso_tickets', [
            'audience_origin' => 'https://deployer.example.test',
            'return_url' => 'https://deployer.example.test/projects/01J8AA00000000000000000000',
        ], 'core');

        $this->post(route('platform.logout'))->assertRedirect(route('platform.login'));

        $this->post(route('platform.login.store'), [
            'email' => 'redirect@example.test',
            'password' => 'correct horse battery staple',
            'return_to' => 'https://attacker.example.test/collect',
        ])->assertRedirect(route('core.home'));
    }

    public function test_one_time_sso_handoff_is_audience_and_origin_bound_and_rejects_replay(): void
    {
        config([
            'platform.products.monitor.url' => 'https://monitor.example.test',
            'platform.products.analytics.url' => 'https://analytics.example.test',
            'lessbuild.trusted_hosts' => ['monitor.example.test', 'analytics.example.test'],
        ]);
        foreach (['monitor.example.test', 'analytics.example.test'] as $host) {
            Route::domain($host)->middleware('web')->group(app_path('Core/Routes/sso.php'));
        }
        $user = $this->createPlatformUser('sso@example.test', 'correct horse battery staple');
        $returnTo = 'https://monitor.example.test/incidents?window=7d#open';

        $handoff = $this->post(route('platform.login.store'), [
            'email' => 'sso@example.test',
            'password' => 'correct horse battery staple',
            'return_to' => $returnTo,
        ]);
        $handoff->assertOk()
            ->assertSee('action="https://monitor.example.test/__platform/sso/exchange"', false)
            ->assertDontSee($returnTo, false);

        preg_match('/<input\b(?=[^>]*\bname="code")(?=[^>]*\bvalue="([a-f0-9]{64})")[^>]*>/', $handoff->getContent(), $matches);
        $this->assertArrayHasKey(1, $matches);
        $plainTextTicket = $matches[1];
        $ticket = DB::connection('core')->table('platform_sso_tickets')->first();
        $this->assertNotNull($ticket);
        $this->assertNotSame($plainTextTicket, $ticket->token_hash);
        $this->assertSame(session('platform.auth.session_id'), $ticket->auth_session_id);

        $this->withHeader('Origin', 'http://localhost')
            ->post('https://analytics.example.test/__platform/sso/exchange', ['code' => $plainTextTicket])
            ->assertForbidden();
        $this->assertNull(DB::connection('core')->table('platform_sso_tickets')->value('consumed_at'));

        $this->withHeaders([
            'Origin' => 'null',
            'Referer' => 'https://attacker.example.test/',
        ])
            ->post('https://monitor.example.test/__platform/sso/exchange', ['code' => $plainTextTicket])
            ->assertForbidden();
        $this->assertNull(DB::connection('core')->table('platform_sso_tickets')->value('consumed_at'));

        $exchange = $this->withHeaders([
            'Origin' => 'null',
            'Referer' => 'http://localhost/',
        ])
            ->post('https://monitor.example.test/__platform/sso/exchange', ['code' => $plainTextTicket]);
        $exchange->assertRedirect($returnTo)
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', (string) $exchange->headers->get('Cache-Control'));
        $this->assertAuthenticatedAs($user, 'platform');
        $this->assertNotNull(DB::connection('core')->table('platform_sso_tickets')->value('consumed_at'));

        $this->withHeader('Origin', 'http://localhost')
            ->post('https://monitor.example.test/__platform/sso/exchange', ['code' => $plainTextTicket])
            ->assertForbidden();
    }

    public function test_logging_out_revokes_the_shared_host_session(): void
    {
        $user = $this->createPlatformUser('logout@example.test', 'correct horse battery staple');

        $this->post(route('platform.login.store'), [
            'email' => 'logout@example.test',
            'password' => 'correct horse battery staple',
        ])->assertRedirect(route('core.home'));

        $authSessionId = session('platform.auth.session_id');
        $this->assertIsString($authSessionId);

        $this->post(route('platform.logout'))->assertRedirect(route('platform.login'));
        $this->assertNotNull(DB::connection('core')->table('platform_auth_sessions')->where('id', $authSessionId)->value('revoked_at'));

        Auth::forgetGuards();
        $sessionKey = Auth::guard('platform')->getName();
        Auth::forgetGuards();
        $this->withSession([
            $sessionKey => $user->getAuthIdentifier(),
            'platform.auth.session_id' => $authSessionId,
        ])->get('/__platform/sso/issue?return_to=https%3A%2F%2Fmonitor.example.test%2F')
            ->assertRedirect(route('platform.login', [
                'return_to' => 'http://localhost/__platform/sso/issue?return_to=https%3A%2F%2Fmonitor.example.test%2F',
            ]));

        $this->assertGuest('platform');
    }

    public function test_dashboard_logout_uses_a_same_host_route_and_revokes_the_shared_session(): void
    {
        $user = $this->createPlatformUser('dashboard-logout@example.test', 'correct horse battery staple');

        $this->post(route('platform.login.store'), [
            'email' => 'dashboard-logout@example.test',
            'password' => 'correct horse battery staple',
        ])->assertRedirect(route('core.home'));

        $authSessionId = session('platform.auth.session_id');
        $this->assertIsString($authSessionId);
        $this->assertSame('/core/logout', route('core.logout', [], false));

        $this->post(route('core.logout'))->assertRedirect(route('platform.login'));

        $this->assertNotNull(DB::connection('core')->table('platform_auth_sessions')
            ->where('id', $authSessionId)
            ->value('revoked_at'));
        $this->assertGuest('platform');
    }

    public function test_account_security_uses_signal_controls_and_shows_core_browser_sessions(): void
    {
        $user = $this->createPlatformUser('account-security@example.test', 'correct horse battery staple');

        $this->withHeader('User-Agent', 'Buildpusher Security Test Browser')
            ->post(route('platform.login.store'), [
                'email' => 'account-security@example.test',
                'password' => 'correct horse battery staple',
            ])
            ->assertRedirect(route('core.home'));

        $this->get(route('platform.account.security'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'private, no-store, max-age=0')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertSee('class="ui-skip-link"', false)
            ->assertSeeText('Account security')
            ->assertSeeText('Buildpusher Security Test Browser')
            ->assertSee('name="current_password"', false)
            ->assertSee('name="password"', false);

        $session = $user->platformAuthSessions()->sole();
        $this->assertNotNull($session->last_seen_at);
        $this->assertSame('Buildpusher Security Test Browser', $session->user_agent);
    }

    public function test_passkey_sign_in_is_available_and_issues_a_session_bound_challenge(): void
    {
        $this->get(route('platform.login'))
            ->assertOk()
            ->assertSeeText('Sign in with a passkey');

        $this->postJson(route('platform.passkey.login.options'))
            ->assertOk()
            ->assertJsonStructure(['options' => ['challenge', 'rpId', 'timeout', 'userVerification']]);
    }

    public function test_account_security_displays_passkey_metadata_without_credential_material(): void
    {
        $user = $this->createPlatformUser('passkey-account@example.test', 'correct horse battery staple');
        $this->post(route('platform.login.store'), [
            'email' => 'passkey-account@example.test',
            'password' => 'correct horse battery staple',
        ])->assertRedirect(route('core.home'));

        DB::connection('core')->table('passkeys')->insert([
            'id' => (string) Str::ulid(),
            'user_id' => $user->getKey(),
            'name' => 'Work laptop',
            'credential_id' => 'credential-id-for-test',
            'credential' => json_encode(['credential_secret_sentinel' => 'do-not-render'], JSON_THROW_ON_ERROR),
            'last_used_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get(route('platform.account.security'))
            ->assertOk()
            ->assertSeeText('Work laptop')
            ->assertSeeText('Authenticator details unavailable')
            ->assertDontSee('do-not-render', false);
    }

    public function test_passkey_registration_options_require_a_platform_session(): void
    {
        $this->post(route('platform.account.passkeys.options'), ['name' => 'Work laptop'])
            ->assertRedirect(route('platform.login'));
    }

    public function test_imported_password_hash_still_requires_the_current_password_when_its_set_date_is_unknown(): void
    {
        $user = $this->createPlatformUser('imported-password@example.test', 'source account password', [
            'password_set_at' => null,
        ]);
        $this->post(route('platform.login.store'), [
            'email' => 'imported-password@example.test',
            'password' => 'source account password',
        ])->assertRedirect(route('core.home'));

        $this->get(route('platform.account.security'))
            ->assertOk()
            ->assertSeeText('Change your password')
            ->assertSee('name="current_password"', false)
            ->assertDontSeeText('Set a password');

        $this->post(route('platform.account.password.update'), [
            'current_password' => 'incorrect source password',
            'password' => 'a newly selected secure password',
            'password_confirmation' => 'a newly selected secure password',
        ])->assertSessionHasErrorsIn('password', 'current_password');

        $this->assertTrue(Hash::check('source account password', $user->fresh()->password));
    }

    public function test_account_security_can_confirm_and_cancel_a_pending_authenticator_setup(): void
    {
        $user = $this->createPlatformUser('authenticator-setup@example.test', 'correct horse battery staple');
        $this->post(route('platform.login.store'), [
            'email' => 'authenticator-setup@example.test',
            'password' => 'correct horse battery staple',
        ])->assertRedirect(route('core.home'));

        $this->get(route('platform.account.security'))->assertOk();
        $this->post(route('platform.account.two-factor.begin'), [
            'current_password' => 'correct horse battery staple',
        ])->assertRedirect();

        $pendingSecret = Crypt::decrypt($user->fresh()->two_factor_secret);
        $this->assertIsString($pendingSecret);
        $this->get(route('platform.account.security'))
            ->assertOk()
            ->assertSeeText('Cancel setup')
            ->assertSeeText($pendingSecret);

        $this->post(route('platform.account.two-factor.confirm'), [
            'code' => $this->totpCode($pendingSecret),
        ])->assertRedirect();

        $user->refresh();
        $this->assertTrue($user->twoFactorEnabled());
        $recoveryHashes = json_decode(Crypt::decrypt($user->two_factor_recovery_codes), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(8, $recoveryHashes);
        $this->assertNotContains('ABCD-1234-EFGH', $recoveryHashes);

        $recoveryCodes = session('platform.auth.recovery_codes');
        $this->assertIsArray($recoveryCodes);
        $this->get(route('platform.account.security'))
            ->assertOk()
            ->assertSeeText('Save these now')
            ->assertSeeText($recoveryCodes[0]);
        $this->get(route('platform.account.security'))
            ->assertOk()
            ->assertDontSee($recoveryCodes[0], false);

        $this->post(route('platform.account.two-factor.begin'), [
            'current_password' => 'correct horse battery staple',
        ])->assertSessionHasErrorsIn('twoFactor', 'code');

        $this->delete(route('platform.account.two-factor.disable'), [
            'current_password' => 'correct horse battery staple',
            'code' => $this->totpCode($pendingSecret),
        ])->assertRedirect();
        $this->assertFalse($user->fresh()->twoFactorEnabled());

        $this->post(route('platform.account.two-factor.begin'), [
            'current_password' => 'correct horse battery staple',
        ])->assertRedirect();
        $this->assertNotNull($user->fresh()->two_factor_secret);
        $this->post(route('platform.account.two-factor.cancel'))->assertRedirect();
        $this->assertNull($user->fresh()->two_factor_secret);
        $this->assertNull($user->fresh()->two_factor_confirmed_at);
    }

    public function test_password_update_revokes_other_platform_browsers_and_keeps_the_current_one(): void
    {
        $user = $this->createPlatformUser('password-update@example.test', 'correct horse battery staple');
        $this->get(route('platform.login'));
        $this->post(route('platform.login.store'), [
            'email' => 'password-update@example.test',
            'password' => 'correct horse battery staple',
            'remember' => true,
        ])->assertRedirect(route('core.home'));

        $currentSessionId = session('platform.auth.session_id');
        $otherSessionId = (string) Str::ulid();
        $oldRememberToken = $user->fresh()->getRememberToken();
        $this->assertIsString($oldRememberToken);
        DB::connection('core')->table('platform_auth_sessions')->insert([
            'id' => $otherSessionId,
            'user_id' => $user->getKey(),
            'remember_token_hash' => hash('sha256', $oldRememberToken),
            'remembered' => true,
            'ip_address' => '192.0.2.10',
            'user_agent' => 'Another browser',
            'last_seen_at' => now(),
            'revoked_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get(route('platform.account.security'))->assertOk();
        $this->post(route('platform.account.password.update'), [
            'current_password' => 'correct horse battery staple',
            'password' => 'a different long secure password',
            'password_confirmation' => 'a different long secure password',
        ])->assertRedirect();

        $this->assertTrue(Hash::check('a different long secure password', $user->fresh()->password));
        $this->assertNotSame($oldRememberToken, $user->fresh()->getRememberToken());
        $this->assertNull(DB::connection('core')->table('platform_auth_sessions')->where('id', $currentSessionId)->value('revoked_at'));
        $this->assertNull(DB::connection('core')->table('platform_auth_sessions')->where('id', $currentSessionId)->value('remember_token_hash'));
        $this->assertNotNull(DB::connection('core')->table('platform_auth_sessions')->where('id', $otherSessionId)->value('revoked_at'));
    }

    private function totpCode(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($secret) as $character) {
            $position = strpos($alphabet, $character);
            $this->assertNotFalse($position);
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $key = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $key .= chr(bindec($byte));
            }
        }

        $counter = intdiv(time(), 30);
        $hash = hash_hmac('sha1', pack('N2', 0, $counter), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($binary % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    /** @param array<string, mixed> $overrides */
    private function createPlatformUser(string $email, string $password, array $overrides = []): PlatformUser
    {
        $now = now();
        $attributes = [
            'id' => (string) Str::ulid(),
            'name' => 'Test Person',
            'email' => $email,
            'email_normalized' => mb_strtolower(trim($email)),
            'password' => Hash::make($password),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
            ...$overrides,
        ];

        DB::connection('core')->table('users')->insert($attributes);

        return PlatformUser::query()->findOrFail($attributes['id']);
    }
}
