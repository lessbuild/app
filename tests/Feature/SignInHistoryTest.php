<?php

namespace Tests\Feature;

use App\Models\SignInEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SignInHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_password_login_records_bounded_client_metadata(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@example.test',
            'password' => Hash::make('correct-password'),
        ]);
        $agent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/140.0 '.str_repeat('x', 600);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.42'])
            ->withHeader('User-Agent', $agent)
            ->post(route('login'), [
                'email' => $user->email,
                'password' => 'correct-password',
            ])->assertRedirect(route('dashboard'));

        $event = $user->signIns()->sole();
        $this->assertSame(SignInEvent::METHOD_PASSWORD, $event->method);
        $this->assertSame('198.51.100.42', $event->ip_address);
        $this->assertSame(500, strlen($event->user_agent));
        $this->assertNotNull($event->signed_in_at);
    }

    public function test_failed_password_login_does_not_create_history(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@example.test',
            'password' => Hash::make('correct-password'),
        ]);

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'incorrect-password',
        ])->assertSessionHasErrors('email');
        $this->post(route('login'), [
            'email' => 'missing@example.test',
            'password' => 'incorrect-password',
        ])->assertSessionHasErrors('email');

        $this->assertDatabaseCount('sign_in_events', 0);
    }

    public function test_account_shows_only_the_owners_recent_derived_sign_in_metadata(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $owner->signIns()->create([
            'method' => 'github',
            'ip_address' => '192.0.2.51',
            'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/140.0 raw-agent-marker',
            'signed_in_at' => now()->subMinute(),
        ]);
        $other->signIns()->create([
            'method' => 'gitlab',
            'ip_address' => '203.0.113.99',
            'user_agent' => 'Foreign browser marker',
            'signed_in_at' => now(),
        ]);

        $this->actingAs($owner)->get(route('account.index'))
            ->assertSuccessful()
            ->assertSee('Recent sign-ins')
            ->assertSee('Chrome on macOS')
            ->assertSee('GitHub')
            ->assertSee('192.0.2.51')
            ->assertDontSee('raw-agent-marker')
            ->assertDontSee('203.0.113.99')
            ->assertDontSee('Foreign browser marker');
    }

    public function test_account_limits_sign_in_history_to_the_ten_newest_records(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 11) as $position) {
            $user->signIns()->create([
                'method' => SignInEvent::METHOD_PASSWORD,
                'ip_address' => "192.0.2.{$position}",
                'user_agent' => 'Mozilla/5.0 Firefox/140.0',
                'signed_in_at' => now()->subMinutes($position),
            ]);
        }

        $this->actingAs($user)->get(route('account.index'))
            ->assertSuccessful()
            ->assertViewHas('recentSignIns', fn ($events): bool => $events->count() === 10)
            ->assertSee('192.0.2.1')
            ->assertDontSee('192.0.2.11');
    }
}
