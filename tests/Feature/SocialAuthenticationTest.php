<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialUser;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SocialAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_supported_social_providers_can_be_requested(): void
    {
        $this->get('/auth/social/redirect/unsupported')->assertNotFound();
        $this->get('/auth/social/callback/unsupported')->assertNotFound();
    }

    public function test_unconfigured_provider_returns_to_login_with_an_error(): void
    {
        config([
            'services.github.client_id' => null,
            'services.github.client_secret' => null,
            'services.github.redirect' => null,
        ]);

        $this->get(route('social.login', 'github'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['social_auth']);
    }

    public function test_auth_pages_only_show_configured_social_providers(): void
    {
        $this->configureProvider('github');
        config([
            'services.gitlab.client_id' => null,
            'services.bitbucket.client_id' => null,
        ]);

        $this->get(route('login'))
            ->assertSuccessful()
            ->assertSee(route('social.login', 'github'))
            ->assertDontSee(route('social.login', 'gitlab'))
            ->assertDontSee(route('social.login', 'bitbucket'));

        $this->get(route('register'))
            ->assertSuccessful()
            ->assertSee(route('social.login', 'github'))
            ->assertDontSee(route('social.login', 'gitlab'))
            ->assertDontSee(route('social.login', 'bitbucket'));
    }

    public function test_configured_provider_redirects_to_oauth(): void
    {
        $this->configureProvider('github');
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('redirect')
            ->once()
            ->andReturn(redirect()->away('https://github.example/oauth'));
        Socialite::shouldReceive('driver')->once()->with('github')->andReturn($provider);

        $this->get(route('social.login', 'github'))
            ->assertRedirect('https://github.example/oauth');
    }

    public function test_callback_creates_and_authenticates_a_social_user(): void
    {
        $this->mockSocialUser('gitlab', $this->socialUser(
            id: 'gitlab-123',
            email: 'SOCIAL@example.com',
            name: 'Social User',
        ));

        $response = $this->get(route('social.callback', 'gitlab'));
        $user = User::query()->where('gitlab_id', 'gitlab-123')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertSame('social@example.com', $user->email);
        $this->assertSame('gitlab', $user->auth_type);
        $this->assertFalse($user->hasLocalPassword());
        $this->assertNotNull($user->email_verified_at);
        $response->assertRedirect(route('dashboard'));
    }

    public function test_callback_links_a_matching_email_instead_of_creating_a_duplicate(): void
    {
        $user = User::factory()->create(['email' => 'linked@example.com']);
        $this->mockSocialUser('bitbucket', $this->socialUser(
            id: 'bitbucket-456',
            email: 'LINKED@example.com',
            name: 'Different Name',
        ));

        $this->get(route('social.callback', 'bitbucket'))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::query()->count());
        $this->assertSame('bitbucket-456', $user->fresh()->bitbucket_id);
        $this->assertNull($user->fresh()->auth_type);
    }

    public function test_closed_registration_rejects_an_unknown_social_identity(): void
    {
        User::factory()->create();
        $this->mockSocialUser('github', $this->socialUser(
            id: 'unknown-github-account',
            email: 'unknown@example.com',
            name: 'Unknown User',
        ));

        $this->get(route('social.callback', 'github'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['social_auth']);

        $this->assertGuest();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseMissing('users', ['email' => 'unknown@example.com']);
    }

    public function test_existing_social_identity_can_sign_in_while_registration_is_closed(): void
    {
        $user = User::factory()->create([
            'email' => 'existing-social@example.com',
            'github_id' => 'existing-github-account',
        ]);
        $this->mockSocialUser('github', $this->socialUser(
            id: 'existing-github-account',
            email: 'existing-social@example.com',
            name: 'Existing User',
        ));

        $this->get(route('social.callback', 'github'))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_explicitly_open_registration_allows_an_additional_social_account(): void
    {
        config(['lessbuild.registration.enabled' => true]);
        User::factory()->create();
        $this->mockSocialUser('gitlab', $this->socialUser(
            id: 'additional-gitlab-account',
            email: 'additional@example.com',
            name: 'Additional User',
        ));

        $this->get(route('social.callback', 'gitlab'))
            ->assertRedirect(route('dashboard'));

        $user = User::query()->where('email', 'additional@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_callback_rejects_a_social_account_without_an_email(): void
    {
        $this->mockSocialUser('github', $this->socialUser(
            id: 'github-789',
            email: null,
            name: 'No Email',
        ));

        $this->get(route('social.callback', 'github'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['social_auth']);

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_oauth_failure_returns_to_login_with_an_error(): void
    {
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->once()->andThrow(new RuntimeException('OAuth failed'));
        Socialite::shouldReceive('driver')->once()->with('github')->andReturn($provider);

        $this->get(route('social.callback', 'github'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['social_auth']);
    }

    private function configureProvider(string $provider): void
    {
        config([
            "services.{$provider}.client_id" => 'client-id',
            "services.{$provider}.client_secret" => 'client-secret',
            "services.{$provider}.redirect" => "https://app.example/auth/social/callback/{$provider}",
        ]);
    }

    private function mockSocialUser(string $providerName, SocialUser $socialUser): void
    {
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->once()->andReturn($socialUser);
        Socialite::shouldReceive('driver')->once()->with($providerName)->andReturn($provider);
    }

    private function socialUser(string $id, ?string $email, ?string $name): SocialUser
    {
        $user = new SocialUser;
        $user->id = $id;
        $user->email = $email;
        $user->name = $name;

        return $user;
    }
}
