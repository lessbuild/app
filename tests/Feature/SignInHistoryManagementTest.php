<?php

namespace Tests\Feature;

use App\Models\SignInEvent;
use App\Models\User;
use App\Notifications\AccountSecurityNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SignInHistoryManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_sign_in_history_actions_require_authentication(): void
    {
        $this->get(route('account.sign-ins.index'))->assertRedirect(route('login'));
        $this->get(route('account.sign-ins.export'))->assertRedirect(route('login'));
        $this->delete(route('account.sign-ins.destroy'))->assertRedirect(route('login'));
    }

    public function test_export_contains_only_derived_owner_metadata_and_never_raw_agents(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->signIn($owner, [
            'method' => 'github',
            'ip_address' => '192.0.2.71',
            'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/140.0 owner-raw-agent-secret',
        ]);
        $this->signIn($owner, [
            'method' => '=FORMULA',
            'ip_address' => '=HYPERLINK',
            'user_agent' => '=raw-formula-agent',
        ]);
        $this->signIn($other, [
            'method' => 'gitlab',
            'ip_address' => '203.0.113.88',
            'user_agent' => 'Foreign raw agent secret',
        ]);

        $response = $this->actingAs($owner)->get(route('account.sign-ins.export'))
            ->assertSuccessful()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('cache-control', 'no-store, private')
            ->assertHeader('x-content-type-options', 'nosniff');
        $this->assertStringContainsString(
            'attachment; filename=lessbuild-sign-ins-',
            (string) $response->headers->get('content-disposition'),
        );

        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString('Chrome on macOS', $content);
        $this->assertStringContainsString('192.0.2.71', $content);
        $this->assertStringContainsString('GitHub', $content);
        $this->assertStringContainsString('Unknown browser on Unknown device', $content);
        $this->assertStringNotContainsString('owner-raw-agent-secret', $content);
        $this->assertStringNotContainsString('raw-formula-agent', $content);
        $this->assertStringNotContainsString('=FORMULA', $content);
        $this->assertStringNotContainsString('=HYPERLINK', $content);
        $this->assertStringNotContainsString('203.0.113.88', $content);
        $this->assertStringNotContainsString('Foreign raw agent secret', $content);
    }

    public function test_history_clear_requires_the_current_password_and_is_owner_scoped(): void
    {
        $owner = User::factory()->create([
            'password' => Hash::make('current-password'),
        ]);
        $other = User::factory()->create();
        $owned = $this->signIn($owner);
        $foreign = $this->signIn($other);

        $this->actingAs($owner)->delete(route('account.sign-ins.destroy'), [
            'current_password' => 'incorrect-password',
        ])->assertSessionHasErrors(['current_password'], errorBag: 'signIns')
            ->assertSessionMissing('_old_input.current_password');
        $this->assertDatabaseHas('sign_in_events', ['id' => $owned->id]);

        $this->delete(route('account.sign-ins.destroy'), [
            'current_password' => 'current-password',
        ])->assertSessionHas('sign_ins_status', '1 sign-in record deleted.');

        $this->assertDatabaseMissing('sign_in_events', ['id' => $owned->id]);
        $this->assertDatabaseHas('sign_in_events', ['id' => $foreign->id]);
        $this->assertDatabaseHas('events', [
            'user_id' => $owner->id,
            'category' => 'account',
            'event' => 'Successful sign-in history was cleared.',
        ]);
        $this->assertDatabaseHas('notifications', [
            'type' => AccountSecurityNotification::class,
            'notifiable_type' => User::class,
            'notifiable_id' => $owner->id,
            'data->message' => 'Successful sign-in history was cleared.',
        ]);
    }

    public function test_account_shows_export_and_local_password_clear_controls(): void
    {
        $local = User::factory()->create();
        $this->signIn($local);

        $this->actingAs($local)->get(route('account.index'))
            ->assertSuccessful()
            ->assertSee(route('account.sign-ins.index'))
            ->assertSee(route('account.sign-ins.export'))
            ->assertSee('method="POST" action="'.route('account.sign-ins.destroy').'"', false)
            ->assertSee('Clear history');

        $social = User::factory()->create([
            'password_set_at' => null,
            'auth_type' => 'github',
            'github_id' => 'social-account',
        ]);
        $this->signIn($social, ['method' => 'github']);
        $this->actingAs($social)->get(route('account.index'))
            ->assertSuccessful()
            ->assertSee(route('account.sign-ins.index'))
            ->assertSee(route('account.sign-ins.export'))
            ->assertDontSee('method="POST" action="'.route('account.sign-ins.destroy').'"', false)
            ->assertSee('Set a local password before clearing sign-in history.');
    }

    /** @param array<string, mixed> $attributes */
    private function signIn(User $user, array $attributes = []): SignInEvent
    {
        return $user->signIns()->create(array_merge([
            'method' => SignInEvent::METHOD_PASSWORD,
            'ip_address' => '192.0.2.70',
            'user_agent' => 'Mozilla/5.0 Firefox/140.0',
            'signed_in_at' => now(),
        ], $attributes));
    }
}
