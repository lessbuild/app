<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Actions\Accounts\ChangeMemberRole;
use App\Actions\Accounts\InviteMember;
use App\Actions\Accounts\RemoveMember;
use App\Data\Accounts\InviteMemberData;
use App\Enums\AccountRole;
use App\Enums\AuditAction;
use App\Models\Account;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

final class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Account changes are logged and shown to those allowed to see them.
     */
    public function test_account_changes_are_logged_and_shown_to_people_allowed_to_see_them(): void
    {
        Notification::fake();
        $owner = User::factory()->create(['name' => 'Olive Owner']);
        $account = Account::factory()->withMember($owner)->create(['name' => 'Acme']);
        $member = User::factory()->create(['name' => 'Max Member', 'email' => 'max@example.com']);
        $membership = $account->memberships()->forceCreate(['user_id' => $member->id, 'role' => AccountRole::Member]);

        app(InviteMember::class)->handle($owner, $account, new InviteMemberData('new@example.com', AccountRole::Viewer));
        app(ChangeMemberRole::class)->handle($owner, $membership->refresh(), AccountRole::Admin);

        $log = $this->actingAs($owner)->getJson('/api/app/account/audit-log')->assertOk()->assertJsonPath('entries.0.actor', 'Olive Owner');
        $descriptions = array_column((array) $log->json('entries'), 'description');
        $this->assertContains('Invited new@example.com as Viewer', $descriptions);
        $this->assertContains('Changed Max Member <max@example.com> from Member to Administrator', $descriptions);

        $viewer = User::factory()->create();
        Account::factory()->withMember($viewer)->create();
        $viewer->forceFill(['current_account_id' => $account->id])->save();
        $account->memberships()->forceCreate(['user_id' => $viewer->id, 'role' => AccountRole::Viewer]);
        $this->actingAs($viewer)->getJson('/api/app/account/audit-log')->assertForbidden();
    }

    /**
     * The log shows only the current account.
     */
    public function test_the_log_only_shows_the_current_account(): void
    {
        $owner = User::factory()->create();
        Account::factory()->withMember($owner)->create();
        $other = User::factory()->create();
        Account::factory()->withMember($other)->create(['name' => 'Secret Corp']);
        (new AuditEntry)->forceFill(['account_id' => $other->current_account_id, 'actor_name' => 'Someone', 'action' => AuditAction::AccountCreated, 'context' => ['name' => 'Secret Corp']])->save();

        $this->actingAs($owner)->getJson('/api/app/account/audit-log')->assertOk()->assertDontSee('Secret Corp');
    }

    /**
     * Entries keep who made them after that person leaves and is deleted.
     */
    public function test_entries_keep_the_actor_after_they_leave_and_are_deleted(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->withMember($owner)->create();
        $leaver = User::factory()->create(['name' => 'Lee Leaver']);
        $membership = $account->memberships()->forceCreate(['user_id' => $leaver->id, 'role' => AccountRole::Member]);

        app(RemoveMember::class)->handle($leaver, $membership);
        $leaver->delete();

        $entry = AuditEntry::query()->where('action', AuditAction::MemberRemoved)->sole();
        $this->assertNull($entry->actor_id);
        $this->assertSame('Lee Leaver', $entry->actor_name);
        $this->assertSame('Left the account', $entry->action->describe($entry->context ?? []));
    }

    /**
     * Personal security changes are logged without an account.
     */
    public function test_personal_security_changes_are_logged_without_an_account(): void
    {
        $user = User::factory()->create(['password' => 'old-password-123']);
        $confirmed = ['auth.password_confirmed_at' => time()];

        $this->actingAs($user)->putJson('/api/app/auth/user/password', ['current_password' => 'old-password-123', 'password' => 'new-password-456', 'password_confirmation' => 'new-password-456'])->assertOk();
        $this->actingAs($user)->withSession($confirmed)->postJson('/api/app/auth/user/two-factor-authentication')->assertOk();
        $secret = (string) Fortify::currentEncrypter()->decrypt((string) $user->refresh()->two_factor_secret);
        $this->actingAs($user)->withSession($confirmed)->postJson('/api/app/auth/user/confirmed-two-factor-authentication', ['code' => app(Google2FA::class)->getCurrentOtp($secret)])->assertOk();
        $this->actingAs($user)->withSession($confirmed)->postJson('/api/app/auth/user/two-factor-recovery-codes')->assertOk();

        $entries = AuditEntry::query()->where('actor_id', $user->id)->orderBy('id')->get();
        $this->assertSame(
            [AuditAction::PasswordChanged, AuditAction::TwoFactorEnabled, AuditAction::RecoveryCodesRegenerated],
            $entries->pluck('action')->all(),
        );
        $this->assertTrue($entries->every(fn (AuditEntry $entry): bool => $entry->account_id === null && $entry->ip_address === '127.0.0.1'));
    }

    /**
     * Entries are pruned after a year.
     */
    public function test_entries_are_pruned_after_a_year(): void
    {
        foreach ([AuditEntry::RETENTION_DAYS + 1, 10] as $daysAgo) {
            (new AuditEntry)->forceFill(['action' => AuditAction::PasswordChanged, 'created_at' => now()->subDays($daysAgo)])->save();
        }

        $this->assertSame(0, Artisan::call('model:prune', ['--model' => [AuditEntry::class]]));
        $this->assertSame(1, AuditEntry::query()->count());
    }
}
