<?php

namespace Tests\Feature\Core;

use App\Core\Models\PlatformUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
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
            $table->string('status', 24)->default('active');
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::connection('core')->create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Auth::forgetGuards();
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        Schema::connection('core')->dropIfExists('password_reset_tokens');
        Schema::connection('core')->dropIfExists('users');

        parent::tearDown();
    }

    public function test_platform_authentication_pages_render_with_the_shared_signal_components(): void
    {
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

        $this->post(route('platform.login.store'), [
            'email' => 'person@example.test',
            'password' => 'shared password',
        ])->assertSessionHasErrors('email');

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
    }

    public function test_platform_redirects_allow_only_configured_origins_or_local_paths(): void
    {
        config(['platform.products.deployer.url' => 'https://deployer.example.test']);

        $this->createPlatformUser('redirect@example.test', 'correct horse battery staple');

        $this->post(route('platform.login.store'), [
            'email' => 'redirect@example.test',
            'password' => 'correct horse battery staple',
            'return_to' => 'https://deployer.example.test/projects/01J8AA00000000000000000000',
        ])->assertRedirect('https://deployer.example.test/projects/01J8AA00000000000000000000');

        $this->post(route('platform.logout'))->assertRedirect(route('platform.login'));

        $this->post(route('platform.login.store'), [
            'email' => 'redirect@example.test',
            'password' => 'correct horse battery staple',
            'return_to' => 'https://attacker.example.test/collect',
        ])->assertRedirect(route('core.home'));
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
