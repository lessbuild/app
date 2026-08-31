<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_password_login_uses_the_dashboard_fallback(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@example.test',
            'password' => Hash::make('correct-password'),
        ]);

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_password_login_returns_to_the_original_filtered_page(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@example.test',
            'password' => Hash::make('correct-password'),
        ]);
        $intended = route('repositories.index', [
            'provider' => 'github',
            'search' => 'storefront release',
        ]);

        $this->get($intended)
            ->assertRedirect(route('login'))
            ->assertSessionHas('url.intended', $intended);

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertRedirect($intended)
            ->assertSessionMissing('url.intended');

        $this->assertAuthenticatedAs($user);
    }

    public function test_failed_login_does_not_discard_the_original_destination(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@example.test',
            'password' => Hash::make('correct-password'),
        ]);
        $intended = route('builds.index', ['status' => 'failed']);

        $this->get($intended)->assertRedirect(route('login'));
        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'incorrect-password',
        ])->assertSessionHasErrors('email')
            ->assertSessionHas('url.intended', $intended);

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertRedirect($intended);
    }

    public function test_unverified_user_returns_to_the_requested_page_then_enters_verification_flow(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'owner@example.test',
            'password' => Hash::make('correct-password'),
        ]);
        $intended = route('servers.index');

        $this->get($intended)->assertRedirect(route('login'));
        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertRedirect($intended);
        $this->get($intended)->assertRedirect(route('verification.notice'));

        $this->assertAuthenticatedAs($user);
    }
}
