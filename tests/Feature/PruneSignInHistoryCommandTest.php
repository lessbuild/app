<?php

namespace Tests\Feature;

use App\Models\SignInEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneSignInHistoryCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_prunes_only_sign_ins_older_than_the_retention_window(): void
    {
        $user = User::factory()->create();
        $old = $this->signIn($user, now()->subDays(91));
        $retained = $this->signIn($user, now()->subDays(89));

        $this->artisan('lessbuild:sign-ins:prune')
            ->expectsOutput('Pruned 1 successful sign-in record(s) older than 90 day(s).')
            ->assertSuccessful();

        $this->assertDatabaseMissing('sign_in_events', ['id' => $old->id]);
        $this->assertDatabaseHas('sign_in_events', ['id' => $retained->id]);
    }

    public function test_command_supports_an_explicit_retention_window(): void
    {
        $user = User::factory()->create();
        $old = $this->signIn($user, now()->subDays(31));

        $this->artisan('lessbuild:sign-ins:prune', ['--days' => 30])
            ->expectsOutput('Pruned 1 successful sign-in record(s) older than 30 day(s).')
            ->assertSuccessful();

        $this->assertDatabaseMissing('sign_in_events', ['id' => $old->id]);
    }

    public function test_invalid_retention_does_not_delete_history(): void
    {
        $user = User::factory()->create();
        $event = $this->signIn($user, now()->subYear());

        $this->artisan('lessbuild:sign-ins:prune', ['--days' => 0])
            ->expectsOutput('Retention days must be a positive integer.')
            ->assertFailed();

        $this->assertDatabaseHas('sign_in_events', ['id' => $event->id]);
    }

    private function signIn(User $user, mixed $signedInAt): SignInEvent
    {
        return $user->signIns()->create([
            'method' => SignInEvent::METHOD_PASSWORD,
            'ip_address' => '192.0.2.60',
            'user_agent' => 'Retention test browser',
            'signed_in_at' => $signedInAt,
        ]);
    }
}
