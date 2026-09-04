<?php

namespace Tests\Feature;

use App\Models\SignInEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignInHistoryInsightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_safe_metrics_for_matching_sign_ins(): void
    {
        $owner = User::factory()->create();
        $latestAt = now()->subMinute();
        $this->signIn($owner, SignInEvent::METHOD_PASSWORD, '192.0.2.10', now()->subDays(5));
        $this->signIn($owner, SignInEvent::METHOD_PASSWORD, '192.0.2.10', now()->subDays(4));
        $this->signIn($owner, 'github', '198.51.100.20', now()->subDays(3));
        $this->signIn($owner, 'gitlab', null, now()->subDays(2));
        $this->signIn($owner, 'legacy-method', '=INVALID-IP', $latestAt, '=raw-agent-secret');

        $this->actingAs($owner)->get(route('account.sign-ins.index'))
            ->assertSuccessful()
            ->assertViewHas('metrics', fn (array $metrics): bool => $metrics['total'] === 5
                && $metrics['password'] === 2
                && $metrics['social'] === 2
                && $metrics['known_ips'] === 2
                && $metrics['latest_at']->timestamp === $latestAt->timestamp)
            ->assertSee('Matching sign-ins')
            ->assertSee('Password sign-ins')
            ->assertSee('Social sign-ins')
            ->assertSee('Known IP addresses')
            ->assertSee('Latest matching sign-in')
            ->assertSee('Recognized GitHub, GitLab, or Bitbucket events.')
            ->assertDontSee('raw-agent-secret');
    }

    public function test_metrics_apply_the_same_method_and_date_filters_as_the_history(): void
    {
        $owner = User::factory()->create();
        $matchingAt = now()->subDay();
        $this->signIn($owner, 'github', '198.51.100.20', $matchingAt);
        $this->signIn($owner, 'github', '198.51.100.21', now()->subDays(10));
        $this->signIn($owner, SignInEvent::METHOD_PASSWORD, '192.0.2.10', $matchingAt);
        $date = $matchingAt->format('Y-m-d');

        $this->actingAs($owner)->get(route('account.sign-ins.index', [
            'method' => 'github',
            'date_from' => $date,
            'date_to' => $date,
        ]))
            ->assertSuccessful()
            ->assertViewHas('metrics', fn (array $metrics): bool => $metrics['total'] === 1
                && $metrics['password'] === 0
                && $metrics['social'] === 1
                && $metrics['known_ips'] === 1
                && $metrics['latest_at']->timestamp === $matchingAt->timestamp);
    }

    public function test_empty_history_has_explicit_zero_and_unknown_metrics(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->get(route('account.sign-ins.index'))
            ->assertSuccessful()
            ->assertViewHas('metrics', [
                'total' => 0,
                'password' => 0,
                'social' => 0,
                'known_ips' => 0,
                'latest_at' => null,
            ])
            ->assertSee('Not available')
            ->assertSee('No matching event recorded.')
            ->assertSee('No sign-in history yet.');
    }

    private function signIn(
        User $user,
        string $method,
        ?string $ipAddress,
        mixed $signedInAt,
        string $userAgent = 'Mozilla/5.0 Firefox/140.0',
    ): SignInEvent {
        return $user->signIns()->create([
            'method' => $method,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'signed_in_at' => $signedInAt,
        ]);
    }
}
