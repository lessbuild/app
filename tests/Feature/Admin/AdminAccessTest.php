<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Account;
use App\Models\PlatformAdminEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

final class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_admin_panel_needs_the_flag_a_second_factor_and_a_recent_confirmation(): void
    {
        $this->get('/admin')->assertRedirect('/login');
        $person = $this->person();
        $this->actingAs($person)->get('/admin')->assertNotFound();

        $person->forceFill(['is_platform_admin' => true])->save();
        $this->actingAs($person)->get('/admin')->assertRedirect(route('settings.security'));

        $person->forceFill(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()])->save();
        $this->actingAs($person)->withSession(['auth.password_confirmed_at' => now()->subMinutes(16)->getTimestamp()])->get('/admin')->assertRedirect(route('password.confirm'));
        $this->actingAs($person)->withSession(['auth.password_confirmed_at' => now()->subMinutes(10)->getTimestamp()])->get('/admin')->assertOk()->assertSee('Admin trail');
        $this->actingAs($person)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])->get('/dashboard')->assertSee('Platform admin');
        $this->actingAs($this->person())->get('/dashboard')->assertDontSee('Platform admin');
    }

    public function test_the_command_grants_revokes_and_lists_admins_keeping_one(): void
    {
        $olive = $this->person('olive@example.com');
        $sam = $this->person('sam@example.com');

        $this->command('platform:admin', ['email' => 'OLIVE@example.com', '--grant' => true])->expectsOutput('Updated olive@example.com.')
            ->expectsOutput('They have no authenticator app or passkey yet; /admin stays closed until they add one.')->assertSuccessful();
        $this->command('platform:admin', ['email' => 'olive@example.com', '--grant' => true])->expectsOutput('No change for olive@example.com.');
        $this->command('platform:admin', ['email' => 'olive@example.com', '--revoke' => true])->expectsOutput('Refusing to revoke the last platform administrator. Use --allow-last to override.')->assertFailed();
        $this->assertTrue($olive->refresh()->is_platform_admin);

        config(['platform.admin_emails' => ['sam@example.com', 'nobody@example.com']]);
        $this->command('platform:admin', ['--import-allowlist' => true])->expectsOutput('Granted sam@example.com')->expectsOutput('No user with nobody@example.com; skipped.');
        $this->command('platform:admin', ['email' => 'olive@example.com', '--revoke' => true])->assertSuccessful();
        $this->assertSame([false, true], [$olive->refresh()->is_platform_admin, $sam->refresh()->is_platform_admin]);
        $this->command('platform:admin', ['email' => 'sam@example.com', '--revoke' => true, '--allow-last' => true])->assertSuccessful();
        $this->command('platform:admin', ['email' => 'olive@example.com'])->assertExitCode(2);
        $this->command('platform:admin', ['email' => 'ghost@example.com', '--grant' => true])->expectsOutput('No user with ghost@example.com.')->assertFailed();

        $this->assertSame(['admin.granted', 'admin.granted', 'admin.revoked', 'admin.revoked'], PlatformAdminEvent::query()->orderBy('id')->pluck('action')->all());
        $this->assertSame(['cli'], PlatformAdminEvent::query()->distinct()->pluck('source')->all());
    }

    /**
     * Run an Artisan command with output expectations.
     *
     * @param  string  $command
     * @param  array<string, mixed>  $parameters
     * @return PendingCommand
     */
    private function command(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);
        $this->assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }

    /**
     * Make a verified person with an account.
     *
     * @param  string|null  $email
     * @return User
     */
    private function person(?string $email = null): User
    {
        $user = User::factory()->create($email === null ? [] : ['email' => $email]);
        Account::factory()->withMember($user)->create();

        return $user->refresh();
    }
}
