<?php

namespace Tests\Feature\Core;

use App\Core\Models\PlatformUser;
use App\Core\Notifications\PlatformVerifyEmail;
use App\Core\Services\Auth\RegisterPlatformAccount;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PlatformRegistrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'lessbuild.registration.enabled' => true,
            'lessbuild.registration.allow_first_user' => false,
        ]);

        Schema::connection('core')->create('users', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('email_normalized')->nullable()->index();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->timestamp('password_set_at')->nullable();
            $table->string('auth_type')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->json('preferences')->nullable();
            $table->string('status', 24)->default('active');
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::connection('core')->create('workspaces', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('owner_user_id', 26);
            $table->string('name');
            $table->string('slug', 120)->unique();
            $table->string('status', 24)->default('active');
            $table->json('settings')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('workspace_memberships', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('user_id', 26);
            $table->string('role', 32);
            $table->string('status', 24);
            $table->char('invited_by_user_id', 26)->nullable();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'user_id']);
        });
        Schema::connection('core')->create('workspace_product_access', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('membership_id', 26);
            $table->string('product', 24);
            $table->string('role', 32)->default('member');
            $table->string('status', 24)->default('active');
            $table->timestamps();
        });
        Schema::connection('core')->create('product_subscriptions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->string('product', 24);
            $table->string('provider', 40);
            $table->string('status', 32);
            $table->timestamps();
        });
        Schema::connection('core')->create('platform_registration_mutexes', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
        });
        DB::connection('core')->table('platform_registration_mutexes')->insert(['id' => 1]);
        Schema::connection('core')->create('platform_auth_sessions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('user_id', 26)->index();
            $table->string('remember_token_hash', 64)->nullable()->index();
            $table->boolean('remembered')->default(false);
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();
        });

        Notification::fake();
        Auth::forgetGuards();
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        Schema::connection('core')->dropIfExists('platform_auth_sessions');
        Schema::connection('core')->dropIfExists('platform_registration_mutexes');
        Schema::connection('core')->dropIfExists('product_subscriptions');
        Schema::connection('core')->dropIfExists('workspace_product_access');
        Schema::connection('core')->dropIfExists('workspace_memberships');
        Schema::connection('core')->dropIfExists('workspaces');
        Schema::connection('core')->dropIfExists('users');

        parent::tearDown();
    }

    public function test_registration_creates_a_core_account_and_owned_workspace_without_granting_products(): void
    {
        $response = $this->post(route('platform.register.store'), [
            'name' => 'Alex Example',
            'email' => ' Alex@Example.test ',
            'password' => 'correct horse battery staple',
            'password_confirmation' => 'correct horse battery staple',
            'workspace_name' => 'Example Team',
        ]);

        $response->assertRedirect(route('platform.verification.notice'));
        $user = PlatformUser::query()->where('email_normalized', 'alex@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($user, 'platform');
        $this->assertSame('active', $user->status);
        $this->assertNull($user->email_verified_at);
        $this->assertTrue(Hash::check('correct horse battery staple', $user->password));

        $workspace = $user->workspaceMemberships()->with('workspace')->sole()->workspace;
        $this->assertSame($user->getKey(), $workspace->owner_user_id);
        $this->assertSame('Example Team', $workspace->name);
        $this->assertSame('owner', $user->workspaceMemberships()->sole()->role);
        $this->assertSame(0, DB::connection('core')->table('workspace_product_access')->count());
        $this->assertSame(0, DB::connection('core')->table('product_subscriptions')->count());
        $this->assertDatabaseHas('platform_auth_sessions', [
            'user_id' => $user->getKey(),
            'remembered' => false,
        ], 'core');
        Notification::assertSentTo($user, PlatformVerifyEmail::class);
        $this->get(route('core.home'))
            ->assertRedirect(route('core.workspace.dashboard', $workspace));
    }

    public function test_registration_screen_uses_signal_components_and_the_login_offers_registration_when_open(): void
    {
        $this->get(route('platform.register'))
            ->assertOk()
            ->assertSeeText('Create your workspace')
            ->assertSee('name="workspace_name"', false)
            ->assertSeeText('No product plan is started during sign-up.');

        $this->get(route('platform.login'))
            ->assertOk()
            ->assertSeeText('Create a workspace');
    }

    public function test_first_account_bootstrap_is_serialized_and_only_creates_one_owner(): void
    {
        config([
            'lessbuild.registration.enabled' => false,
            'lessbuild.registration.allow_first_user' => true,
        ]);
        $registration = app(RegisterPlatformAccount::class);

        $this->assertTrue($registration->available());
        $firstUser = $registration->handle(
            'First Owner',
            'first@example.test',
            'a secure first account password',
            'First Workspace',
        );

        try {
            $registration->handle(
                'Second Owner',
                'second@example.test',
                'a secure second account password',
                'Second Workspace',
            );
            $this->fail('Only the first Core account may use the bootstrap registration exception.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('registration', $exception->errors());
        }

        $this->assertSame(1, PlatformUser::query()->count());
        $this->assertSame(1, DB::connection('core')->table('workspaces')->count());
        $this->assertSame(1, $firstUser->workspaceMemberships()->count());
    }

    public function test_registration_rejects_existing_normalized_email_without_merging_accounts(): void
    {
        PlatformUser::query()->create([
            'id' => (string) Str::ulid(),
            'name' => 'Imported Account',
            'email' => 'Person@example.test',
            'email_normalized' => 'person@example.test',
            'password' => Hash::make('an imported account password'),
            'status' => 'active',
        ]);

        $this->post(route('platform.register.store'), [
            'name' => 'New Person',
            'email' => ' PERSON@example.test ',
            'password' => 'a new person secure password',
            'password_confirmation' => 'a new person secure password',
            'workspace_name' => 'New Team',
        ])->assertSessionHasErrors('email');

        $this->assertSame(1, PlatformUser::query()->count());
        $this->assertSame(0, DB::connection('core')->table('workspaces')->count());
    }

    public function test_signed_in_platform_user_cannot_create_another_account_through_registration(): void
    {
        $existing = app(RegisterPlatformAccount::class)->handle(
            'Existing Owner',
            'existing-owner@example.test',
            'an existing owner secure password',
            'Existing Workspace',
        );

        $this->actingAs($existing, 'platform')
            ->post(route('platform.register.store'), [
                'name' => 'Unexpected Account',
                'email' => 'unexpected@example.test',
                'password' => 'an unexpected account password',
                'password_confirmation' => 'an unexpected account password',
                'workspace_name' => 'Unexpected Workspace',
            ])
            ->assertRedirect(route('core.home'));

        $this->assertSame(1, PlatformUser::query()->count());
        $this->assertSame(1, DB::connection('core')->table('workspaces')->count());
    }

    public function test_signed_core_email_verification_marks_only_the_authenticated_matching_account(): void
    {
        $user = app(RegisterPlatformAccount::class)->handle(
            'Verified User',
            'verified@example.test',
            'a secure verification password',
            'Verification Workspace',
        );
        $url = URL::temporarySignedRoute('platform.verification.verify', now()->addMinutes(30), [
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->actingAs($user, 'platform')
            ->get($url)
            ->assertRedirect(route('core.home'));

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_platform_verification_notification_targets_its_signed_core_route(): void
    {
        $user = app(RegisterPlatformAccount::class)->handle(
            'Mail Link User',
            'mail-link@example.test',
            'a secure notification password',
            'Mail Link Workspace',
        );
        $message = (new PlatformVerifyEmail)->toMail($user);
        $expectedPath = parse_url(route('platform.verification.verify', [
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ]), PHP_URL_PATH);

        $this->assertSame($expectedPath, parse_url($message->actionUrl, PHP_URL_PATH));
        parse_str((string) parse_url($message->actionUrl, PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('expires', $query);
        $this->assertArrayHasKey('signature', $query);
    }

    public function test_registration_is_closed_after_bootstrap_when_public_registration_is_disabled(): void
    {
        config([
            'lessbuild.registration.enabled' => false,
            'lessbuild.registration.allow_first_user' => false,
        ]);

        $this->get(route('platform.register'))->assertNotFound();
        $this->post(route('platform.register.store'), [])->assertNotFound();
    }
}
