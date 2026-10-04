<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Data\Users\SocialProfile;
use App\Enums\AccountRole;
use App\Enums\SocialProvider;
use App\Events\Users\SocialIdentityConnected;
use App\Events\Users\SocialIdentityDisconnected;
use App\Models\SocialIdentity;
use App\Models\User;
use App\Services\SocialSignIn\SocialSignInGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Fortify\Features;
use Tests\Fakes\FakeSocialSignInGateway;
use Tests\TestCase;

final class SocialSignInTest extends TestCase
{
    use RefreshDatabase;

    private FakeSocialSignInGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new FakeSocialSignInGateway;
        $this->app->instance(SocialSignInGateway::class, $this->gateway);
    }

    /**
     * Sign in pages offer only configured providers.
     */
    public function test_sign_in_pages_offer_only_configured_providers(): void
    {
        $keys = array_column((array) $this->getJson('/api/app/auth/options')->assertOk()->json('socialProviders'), 'key');
        $this->assertContains('github', $keys);
        $this->assertContains('gitlab', $keys);
        $this->assertNotContains('bitbucket', $keys);

        $this->gateway->configured = [];
        $this->getJson('/api/app/auth/options')->assertOk()->assertJsonPath('socialProviders', []);
    }

    /**
     * The redirect goes to the provider and unknown or unconfigured providers are refused.
     */
    public function test_the_redirect_goes_to_the_provider_and_unknown_or_unconfigured_providers_are_refused(): void
    {
        $this->get('/auth/github/redirect')->assertRedirect('https://github.test/authorize');
        $this->get('/auth/bitbucket/redirect')->assertRedirect('/login')->assertSessionHasErrors('social');
        $this->get('/auth/myspace/redirect')->assertNotFound();
    }

    /**
     * A new person is registered verified and signed in.
     */
    public function test_a_new_person_is_registered_verified_and_signed_in(): void
    {
        Event::fake([SocialIdentityConnected::class]);
        $this->gateway->profile = new SocialProfile('gh-1', 'Grace@Example.com', 'Grace Hopper');

        $this->get('/auth/github/callback?code=x')->assertRedirect('/dashboard');

        $user = User::query()->where('email', 'grace@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->password);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertSame(AccountRole::Owner, $user->currentAccount?->roleOf($user));
        $this->assertTrue($user->socialIdentities()->where('provider', SocialProvider::GitHub)->where('provider_user_id', 'gh-1')->exists());
        Event::assertDispatched(SocialIdentityConnected::class);
    }

    /**
     * A connected identity signs in its user and returns to the intended page.
     */
    public function test_a_connected_identity_signs_in_its_user_and_returns_to_the_intended_page(): void
    {
        $user = User::factory()->create();
        $this->connect($user, SocialProvider::GitHub, 'gh-7');
        $this->gateway->profile = new SocialProfile('gh-7', 'someone-else@example.com', 'Name');

        $this->withSession(['url.intended' => url('/invitations/abc')])->get('/auth/github/callback?code=x')->assertRedirect('/invitations/abc');

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull(SocialIdentity::query()->where('provider_user_id', 'gh-7')->value('last_used_at'));
    }

    /**
     * An existing email is never taken over by a new provider identity.
     */
    public function test_an_existing_email_is_never_taken_over_by_a_new_provider_identity(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        $this->gateway->profile = new SocialProfile('gh-2', 'ada@example.com', 'Impostor');

        $this->get('/auth/github/callback?code=x')->assertRedirect('/login')->assertSessionHasErrors('social');

        $this->assertGuest();
        $this->assertSame(0, $user->socialIdentities()->count());
    }

    /**
     * Profiles without a verified email or with registration closed are refused.
     */
    public function test_profiles_without_a_verified_email_or_with_registration_closed_are_refused(): void
    {
        $this->gateway->profile = new SocialProfile('gh-3', null, 'No Email');
        $this->get('/auth/github/callback?code=x')->assertRedirect('/login')->assertSessionHasErrors('social');

        config(['fortify.features' => array_values(array_filter(config('fortify.features'), fn ($feature): bool => $feature !== Features::registration()))]);
        $this->gateway->profile = new SocialProfile('gh-4', 'new@example.com', 'New');
        $this->get('/auth/github/callback?code=x')->assertRedirect('/login')->assertSessionHasErrors('social');

        $this->assertGuest();
        $this->assertSame(0, User::query()->count());
    }

    /**
     * Cancelled or failed provider callbacks return to sign in.
     */
    public function test_cancelled_or_failed_provider_callbacks_return_to_sign_in(): void
    {
        $this->get('/auth/github/callback?error=access_denied')->assertRedirect('/login')->assertSessionHasErrors('social');
        $this->get('/auth/github/callback?code=x')->assertRedirect('/login')->assertSessionHasErrors('social');
        $this->assertGuest();
    }

    /**
     * Users with two factor still face the challenge.
     */
    public function test_users_with_two_factor_still_face_the_challenge(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
        $this->connect($user, SocialProvider::GitHub, 'gh-8');
        $this->gateway->profile = new SocialProfile('gh-8', $user->email, 'Name');

        $this->get('/auth/github/callback?code=x')->assertRedirect('/two-factor-challenge')->assertSessionHas('login.id', $user->id);
        $this->assertGuest();
    }

    /**
     * Signed in users connect a provider from security settings.
     */
    public function test_signed_in_users_connect_a_provider_from_security_settings(): void
    {
        Event::fake([SocialIdentityConnected::class]);
        $user = User::factory()->create();
        $confirmed = ['auth.password_confirmed_at' => time()];

        $this->actingAs($user)->postJson('/api/app/settings/security/social/github')->assertStatus(423);
        $this->actingAs($user)->withSession($confirmed)->getJson('/api/app/settings/security')->assertOk()->assertJsonPath('providers.0.key', 'github');

        $this->actingAs($user)->withSession($confirmed)->postJson('/api/app/settings/security/social/github')->assertOk()->assertJsonPath('redirect', 'https://github.test/authorize');
        $this->gateway->profile = new SocialProfile('gh-9', 'ada@work.example', 'Ada');
        $this->actingAs($user)->get('/auth/github/callback?code=x')->assertRedirect('/settings/security?connected=new');

        $this->assertSame('ada@work.example', $user->socialIdentities()->value('email'));
        Event::assertDispatched(SocialIdentityConnected::class);
    }

    /**
     * A callback without a matching request from this browser is rejected.
     */
    public function test_a_callback_without_a_matching_request_from_this_browser_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->gateway->profile = new SocialProfile('gh-10', 'x@example.com', 'X');

        $this->assertStringStartsWith(url('/settings/security?social_error='), (string) $this->actingAs($user)->get('/auth/github/callback?code=x')->assertRedirect()->headers->get('Location'));

        $stale = ['social.intent' => ['type' => 'connect', 'provider' => 'github', 'user_id' => $user->id, 'at' => now()->subHour()->getTimestamp()]];
        $this->assertStringContainsString('social_error=', (string) $this->actingAs($user)->withSession($stale)->get('/auth/github/callback?code=x')->headers->get('Location'));

        $this->assertSame(0, SocialIdentity::query()->count());
    }

    /**
     * An identity owned by someone else cannot be connected.
     */
    public function test_an_identity_owned_by_someone_else_cannot_be_connected(): void
    {
        $owner = User::factory()->create();
        $this->connect($owner, SocialProvider::GitHub, 'gh-11');
        $user = User::factory()->create();
        $this->gateway->profile = new SocialProfile('gh-11', 'x@example.com', 'X');

        $intent = ['social.intent' => ['type' => 'connect', 'provider' => 'github', 'user_id' => $user->id, 'at' => now()->getTimestamp()]];
        $this->assertStringContainsString('social_error=', (string) $this->actingAs($user)->withSession($intent)->get('/auth/github/callback?code=x')->assertRedirect()->headers->get('Location'));

        $this->assertSame(0, $user->socialIdentities()->count());
    }

    /**
     * The last sign in method cannot be disconnected.
     */
    public function test_the_last_sign_in_method_cannot_be_disconnected(): void
    {
        Event::fake([SocialIdentityDisconnected::class]);
        $user = User::factory()->create(['password' => null]);
        $this->connect($user, SocialProvider::GitHub, 'gh-12');
        $confirmed = ['auth.password_confirmed_at' => time()];

        $this->actingAs($user)->withSession($confirmed)->deleteJson('/api/app/settings/security/social/github')->assertJsonValidationErrors('social');
        $this->assertSame(1, $user->socialIdentities()->count());

        $this->connect($user, SocialProvider::GitLab, 'gl-1');
        $this->actingAs($user)->withSession($confirmed)->deleteJson('/api/app/settings/security/social/github')->assertOk()->assertJsonPath('message', __('Account disconnected.'));
        $this->assertFalse($user->socialIdentities()->where('provider', SocialProvider::GitHub)->exists());
        Event::assertDispatched(SocialIdentityDisconnected::class);
    }

    /**
     * Passwordless users confirm sensitive actions with their provider.
     */
    public function test_passwordless_users_confirm_sensitive_actions_with_their_provider(): void
    {
        $user = User::factory()->create(['password' => null]);
        $this->connect($user, SocialProvider::GitHub, 'gh-13');

        $this->actingAs($user)->getJson('/api/app/settings/security')->assertStatus(423);
        $me = $this->actingAs($user)->getJson('/api/app/auth/me')->assertOk()->assertJsonPath('hasPassword', false);
        $this->assertSame(['github'], array_column((array) $me->json('confirmProviders'), 'key'));

        $this->actingAs($user)->postJson('/api/app/auth/confirm-with/github', ['redirect' => '/settings/security'])->assertOk()->assertJsonPath('redirect', 'https://github.test/authorize');

        $this->gateway->profile = new SocialProfile('gh-other', 'x@example.com', 'X');
        $this->assertStringStartsWith(url('/user/confirm-password?social_error='), (string) $this->actingAs($user)->get('/auth/github/callback?code=x')->assertRedirect()->headers->get('Location'));

        $this->actingAs($user)->postJson('/api/app/auth/confirm-with/github', ['redirect' => '/settings/security'])->assertOk();
        $this->gateway->profile = new SocialProfile('gh-13', 'x@example.com', 'X');
        $this->actingAs($user)->get('/auth/github/callback?code=x')->assertRedirect('/settings/security');
        $this->actingAs($user)->getJson('/api/app/settings/security')->assertOk()->assertJsonPath('security.hasPassword', false);
    }

    private function connect(User $user, SocialProvider $provider, string $providerUserId): void
    {
        $identity = new SocialIdentity;
        $identity->forceFill(['user_id' => $user->id, 'provider' => $provider, 'provider_user_id' => $providerUserId, 'email' => $user->email])->save();
    }
}
