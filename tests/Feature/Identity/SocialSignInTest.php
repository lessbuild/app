<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domain\Accounts\Enums\AccountRole;
use App\Domain\Identity\Data\SocialProfile;
use App\Domain\Identity\Enums\SocialProvider;
use App\Domain\Identity\Events\SocialIdentityConnected;
use App\Domain\Identity\Events\SocialIdentityDisconnected;
use App\Domain\Identity\Models\SocialIdentity;
use App\Domain\Identity\Models\User;
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

    public function test_sign_in_pages_offer_only_configured_providers(): void
    {
        $this->get('/login')->assertOk()->assertSee(route('social.redirect', 'github'), false)->assertDontSee(route('social.redirect', 'bitbucket'), false);
        $this->get('/register')->assertOk()->assertSee(route('social.redirect', 'gitlab'), false);

        $this->gateway->configured = [];
        $this->get('/login')->assertOk()->assertDontSee(__('Or continue with'));
    }

    public function test_the_redirect_goes_to_the_provider_and_unknown_or_unconfigured_providers_are_refused(): void
    {
        $this->get('/auth/github/redirect')->assertRedirect('https://github.test/authorize');
        $this->get('/auth/bitbucket/redirect')->assertRedirect('/login')->assertSessionHasErrors('social');
        $this->get('/auth/myspace/redirect')->assertNotFound();
    }

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

    public function test_a_connected_identity_signs_in_its_user_and_returns_to_the_intended_page(): void
    {
        $user = User::factory()->create();
        $this->connect($user, SocialProvider::GitHub, 'gh-7');
        $this->gateway->profile = new SocialProfile('gh-7', 'someone-else@example.com', 'Name');

        $this->withSession(['url.intended' => url('/invitations/abc')])->get('/auth/github/callback?code=x')->assertRedirect('/invitations/abc');

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull(SocialIdentity::query()->where('provider_user_id', 'gh-7')->value('last_used_at'));
    }

    public function test_an_existing_email_is_never_taken_over_by_a_new_provider_identity(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        $this->gateway->profile = new SocialProfile('gh-2', 'ada@example.com', 'Impostor');

        $this->get('/auth/github/callback?code=x')->assertRedirect('/login')->assertSessionHasErrors('social');

        $this->assertGuest();
        $this->assertSame(0, $user->socialIdentities()->count());
    }

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

    public function test_cancelled_or_failed_provider_callbacks_return_to_sign_in(): void
    {
        $this->get('/auth/github/callback?error=access_denied')->assertRedirect('/login')->assertSessionHasErrors('social');
        $this->get('/auth/github/callback?code=x')->assertRedirect('/login')->assertSessionHasErrors('social');
        $this->assertGuest();
    }

    public function test_users_with_two_factor_still_face_the_challenge(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
        $this->connect($user, SocialProvider::GitHub, 'gh-8');
        $this->gateway->profile = new SocialProfile('gh-8', $user->email, 'Name');

        $this->get('/auth/github/callback?code=x')->assertRedirect('/two-factor-challenge')->assertSessionHas('login.id', $user->id);
        $this->assertGuest();
    }

    public function test_signed_in_users_connect_a_provider_from_security_settings(): void
    {
        Event::fake([SocialIdentityConnected::class]);
        $user = User::factory()->create();
        $confirmed = ['auth.password_confirmed_at' => time()];

        $this->actingAs($user)->post('/settings/security/social/github')->assertRedirect('/user/confirm-password');
        $this->actingAs($user)->withSession($confirmed)->get('/settings/security')->assertOk()->assertSee(route('social.connect', 'github'), false);

        $this->actingAs($user)->withSession($confirmed)->post('/settings/security/social/github')->assertRedirect('https://github.test/authorize');
        $this->gateway->profile = new SocialProfile('gh-9', 'ada@work.example', 'Ada');
        $this->actingAs($user)->get('/auth/github/callback?code=x')->assertRedirect('/settings/security')->assertSessionHas('status', 'social-connected');

        $this->assertSame('ada@work.example', $user->socialIdentities()->value('email'));
        Event::assertDispatched(SocialIdentityConnected::class);
    }

    public function test_a_callback_without_a_matching_request_from_this_browser_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->gateway->profile = new SocialProfile('gh-10', 'x@example.com', 'X');

        $this->actingAs($user)->get('/auth/github/callback?code=x')->assertRedirect('/settings/security')->assertSessionHasErrorsIn('social', 'social');

        $stale = ['social.intent' => ['type' => 'connect', 'provider' => 'github', 'user_id' => $user->id, 'at' => now()->subHour()->getTimestamp()]];
        $this->actingAs($user)->withSession($stale)->get('/auth/github/callback?code=x')->assertSessionHasErrorsIn('social', 'social');

        $this->assertSame(0, SocialIdentity::query()->count());
    }

    public function test_an_identity_owned_by_someone_else_cannot_be_connected(): void
    {
        $owner = User::factory()->create();
        $this->connect($owner, SocialProvider::GitHub, 'gh-11');
        $user = User::factory()->create();
        $this->gateway->profile = new SocialProfile('gh-11', 'x@example.com', 'X');

        $intent = ['social.intent' => ['type' => 'connect', 'provider' => 'github', 'user_id' => $user->id, 'at' => now()->getTimestamp()]];
        $this->actingAs($user)->withSession($intent)->get('/auth/github/callback?code=x')->assertRedirect('/settings/security')->assertSessionHasErrorsIn('social', 'social');

        $this->assertSame(0, $user->socialIdentities()->count());
    }

    public function test_the_last_sign_in_method_cannot_be_disconnected(): void
    {
        Event::fake([SocialIdentityDisconnected::class]);
        $user = User::factory()->create(['password' => null]);
        $this->connect($user, SocialProvider::GitHub, 'gh-12');
        $confirmed = ['auth.password_confirmed_at' => time()];

        $this->actingAs($user)->withSession($confirmed)->delete('/settings/security/social/github')->assertSessionHasErrorsIn('social', 'social');
        $this->assertSame(1, $user->socialIdentities()->count());

        $this->connect($user, SocialProvider::GitLab, 'gl-1');
        $this->actingAs($user)->withSession($confirmed)->delete('/settings/security/social/github')->assertSessionHas('status', 'social-disconnected');
        $this->assertFalse($user->socialIdentities()->where('provider', SocialProvider::GitHub)->exists());
        Event::assertDispatched(SocialIdentityDisconnected::class);
    }

    public function test_passwordless_users_confirm_sensitive_actions_with_their_provider(): void
    {
        $user = User::factory()->create(['password' => null]);
        $this->connect($user, SocialProvider::GitHub, 'gh-13');

        $this->actingAs($user)->get('/settings/security')->assertRedirect('/user/confirm-password');
        $this->actingAs($user)->get('/user/confirm-password')
            ->assertOk()
            ->assertDontSee('name="password"', false)
            ->assertSee(route('social.confirm', 'github'), false)
            ->assertDontSee(route('social.confirm', 'gitlab'), false);

        $this->actingAs($user)->post('/user/confirm-password/github')->assertRedirect('https://github.test/authorize');

        $this->gateway->profile = new SocialProfile('gh-other', 'x@example.com', 'X');
        $this->actingAs($user)->get('/auth/github/callback?code=x')->assertRedirect('/user/confirm-password')->assertSessionHasErrorsIn('social', 'social');

        $this->actingAs($user)->post('/user/confirm-password/github');
        $this->gateway->profile = new SocialProfile('gh-13', 'x@example.com', 'X');
        $this->actingAs($user)->get('/auth/github/callback?code=x')->assertRedirect('/settings/security');
        $this->actingAs($user)->get('/settings/security')->assertOk()->assertSee(__('Set a password'));
    }

    private function connect(User $user, SocialProvider $provider, string $providerUserId): void
    {
        $identity = new SocialIdentity;
        $identity->forceFill(['user_id' => $user->id, 'provider' => $provider, 'provider_user_id' => $providerUserId, 'email' => $user->email])->save();
    }
}
