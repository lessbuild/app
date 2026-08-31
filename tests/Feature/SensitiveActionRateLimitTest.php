<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SensitiveActionRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_sensitive_authenticated_attempts_share_a_per_user_limit(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('current-password'),
            'github_id' => 'github-account',
        ]);
        $originalHash = $user->password;
        $this->actingAs($user);

        foreach (range(1, 3) as $_) {
            $this->post(route('password.confirm'), ['password' => 'incorrect-password'])
                ->assertRedirect()
                ->assertSessionHasErrors('password');
        }

        foreach (range(1, 3) as $_) {
            $this->post(route('account.sessions.revoke'), ['current_password' => 'incorrect-password'])
                ->assertRedirect()
                ->assertSessionHasErrors(['current_password'], errorBag: 'sessions');
        }

        $this->patch(route('account.profile.update'), [
            'name' => $user->name,
            'email' => 'rate-limited@example.test',
            'current_password' => 'current-password',
        ])->assertTooManyRequests();
        $this->assertNotSame('rate-limited@example.test', $user->fresh()->email);

        $this->delete(route('account.social.destroy', 'github'), [
            'social_provider' => 'github',
            'current_password' => 'current-password',
        ])->assertTooManyRequests();
        $this->assertSame('github-account', $user->fresh()->github_id);

        $sessionId = str_repeat('s', 40);
        DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => $user->id,
            'ip_address' => '192.0.2.40',
            'user_agent' => 'Rate limit test browser',
            'payload' => base64_encode(''),
            'last_activity' => now()->timestamp,
        ]);
        $this->delete(route('account.sessions.destroy', $sessionId), [
            'session_id' => $sessionId,
            'current_password' => 'current-password',
        ])->assertTooManyRequests();
        $this->assertDatabaseHas('sessions', ['id' => $sessionId]);

        $this->patch(route('account.password.update'), [
            'current_password' => 'current-password',
            'password' => 'replacement-password',
            'password_confirmation' => 'replacement-password',
        ])->assertTooManyRequests();

        $this->assertSame($originalHash, $user->fresh()->password);

        $other = User::factory()->create([
            'password' => Hash::make('other-password'),
        ]);
        $this->app['session']->flush();
        $this->actingAs($other)
            ->post(route('account.sessions.revoke'), ['current_password' => 'incorrect-password'])
            ->assertRedirect()
            ->assertSessionHasErrors(['current_password'], errorBag: 'sessions');
    }

    public function test_guest_reset_link_and_reset_submission_attempts_share_an_ip_email_limit(): void
    {
        Notification::fake();
        $ip = '198.51.100.41';
        $email = 'limited-reset@example.test';
        User::factory()->create(['email' => $email]);
        $this->withServerVariables(['REMOTE_ADDR' => $ip]);

        foreach (range(1, 6) as $_) {
            $this->post(route('password.email'), ['email' => $email])->assertRedirect();
        }

        $this->post(route('password.update'), [
            'token' => 'invalid-reset-token',
            'email' => $email,
            'password' => 'replacement-password',
            'password_confirmation' => 'replacement-password',
        ])->assertTooManyRequests();

        $this->post(route('password.email'), ['email' => 'different-reset@example.test'])
            ->assertRedirect();
    }

    public function test_guest_sensitive_attempts_have_a_broader_ip_ceiling_across_email_addresses(): void
    {
        Notification::fake();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.42']);

        foreach (range(1, 20) as $position) {
            $this->post(route('password.email'), [
                'email' => "rotated-{$position}@example.test",
            ])->assertRedirect();
        }

        $this->post(route('password.email'), [
            'email' => 'rotated-21@example.test',
        ])->assertTooManyRequests();
    }
}
