<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\TestCase;

class PasswordConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_password_user_must_confirm_before_starting_a_social_connection(): void
    {
        $this->configureGithub();
        $user = User::factory()->create([
            'password' => Hash::make('current-password'),
            'password_set_at' => now(),
        ]);
        $connect = route('account.social.connect', 'github');

        $this->actingAs($user)->get($connect)
            ->assertRedirect(route('password.confirm'));
        $this->get(route('password.confirm'))
            ->assertSuccessful()
            ->assertSee('Confirm your password');
        $this->post(route('password.confirm'), ['password' => 'wrong-password'])
            ->assertSessionHasErrors('password');
        $this->assertNull(session('auth.password_confirmed_at'));

        $this->post(route('password.confirm'), ['password' => 'current-password'])
            ->assertRedirect($connect);
        $this->assertIsInt(session('auth.password_confirmed_at'));
        $this->assertArrayNotHasKey('scenes::auth.password_confirmed_at', session()->all());

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('redirect')
            ->once()
            ->andReturn(redirect()->away('https://github.example/oauth'));
        Socialite::shouldReceive('driver')->once()->with('github')->andReturn($provider);
        $this->get($connect)->assertRedirect('https://github.example/oauth');
    }

    public function test_expired_confirmation_requires_the_password_again(): void
    {
        $this->configureGithub();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => now()->subSeconds(config('auth.password_timeout') + 1)->timestamp])
            ->get(route('account.social.connect', 'github'))
            ->assertRedirect(route('password.confirm'));
    }

    public function test_social_only_account_bypasses_local_password_confirmation(): void
    {
        $this->configureGithub();
        $user = User::factory()->create([
            'password_set_at' => null,
            'auth_type' => 'gitlab',
            'gitlab_id' => 'existing-gitlab-account',
        ]);
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('redirect')
            ->once()
            ->andReturn(redirect()->away('https://github.example/oauth'));
        Socialite::shouldReceive('driver')->once()->with('github')->andReturn($provider);

        $this->actingAs($user)->get(route('account.social.connect', 'github'))
            ->assertRedirect('https://github.example/oauth');
        $this->get(route('password.confirm'))
            ->assertRedirect(route('account.index'))
            ->assertSessionHas('social_error', 'This account does not have a local password to confirm.');
    }

    public function test_confirmation_routes_require_authentication(): void
    {
        $this->get(route('password.confirm'))->assertRedirect(route('login'));
        $this->post(route('password.confirm'), ['password' => 'password'])
            ->assertRedirect(route('login'));
    }

    private function configureGithub(): void
    {
        config([
            'services.github.client_id' => 'client-id',
            'services.github.client_secret' => 'client-secret',
            'services.github.redirect' => 'https://app.example/auth/social/callback/github',
        ]);
    }
}
