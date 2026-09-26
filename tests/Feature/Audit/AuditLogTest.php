<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Domain\Accounts\Actions\ChangeMemberRole;
use App\Domain\Accounts\Actions\InviteMember;
use App\Domain\Accounts\Actions\RemoveMember;
use App\Domain\Accounts\Data\InviteMemberData;
use App\Domain\Accounts\Enums\AccountRole;
use App\Domain\Accounts\Models\Account;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

final class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_changes_are_logged_and_shown_to_people_allowed_to_see_them(): void
    {
        Notification::fake();
        $owner = User::factory()->create(['name' => 'Olive Owner']);
        $account = Account::factory()->withMember($owner)->create(['name' => 'Acme']);
        $member = User::factory()->create(['name' => 'Max Member', 'email' => 'max@example.com']);
        $membership = $account->memberships()->forceCreate(['user_id' => $member->id, 'role' => AccountRole::Member]);

        app(InviteMember::class)->handle($owner, $account, new InviteMemberData('new@example.com', AccountRole::Viewer));
        app(ChangeMemberRole::class)->handle($owner, $membership->refresh(), AccountRole::Admin);

        $this->actingAs($owner)->get('/account/audit-log')
            ->assertOk()
            ->assertSee('Olive Owner')
            ->assertSee('Invited new@example.com as Viewer')
            ->assertSee('Changed Max Member &lt;max@example.com&gt; from Member to Administrator', false);

        $viewer = User::factory()->create();
        Account::factory()->withMember($viewer)->create();
        $viewer->forceFill(['current_account_id' => $account->id])->save();
        $account->memberships()->forceCreate(['user_id' => $viewer->id, 'role' => AccountRole::Viewer]);
        $this->actingAs($viewer)->get('/account/audit-log')->assertForbidden();
    }

    public function test_the_log_only_shows_the_current_account(): void
    {
        $owner = User::factory()->create();
        Account::factory()->withMember($owner)->create();
        $other = User::factory()->create();
        Account::factory()->withMember($other)->create(['name' => 'Secret Corp']);
        (new AuditEntry)->forceFill(['account_id' => $other->current_account_id, 'actor_name' => 'Someone', 'action' => AuditAction::AccountCreated, 'context' => ['name' => 'Secret Corp']])->save();

        $this->actingAs($owner)->get('/account/audit-log')->assertOk()->assertDontSee('Secret Corp');
    }

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

    public function test_personal_security_changes_are_logged_without_an_account(): void
    {
        $user = User::factory()->create(['password' => 'old-password-123']);
        $confirmed = ['auth.password_confirmed_at' => time()];

        $this->actingAs($user)->put('/user/password', ['current_password' => 'old-password-123', 'password' => 'new-password-456', 'password_confirmation' => 'new-password-456']);
        $this->actingAs($user)->withSession($confirmed)->post('/user/two-factor-authentication');
        $secret = (string) Fortify::currentEncrypter()->decrypt((string) $user->refresh()->two_factor_secret);
        $this->actingAs($user)->withSession($confirmed)->post('/user/confirmed-two-factor-authentication', ['code' => app(Google2FA::class)->getCurrentOtp($secret)]);
        $this->actingAs($user)->withSession($confirmed)->post('/user/two-factor-recovery-codes');

        $entries = AuditEntry::query()->where('actor_id', $user->id)->orderBy('id')->get();
        $this->assertSame(
            [AuditAction::PasswordChanged, AuditAction::TwoFactorEnabled, AuditAction::RecoveryCodesRegenerated],
            $entries->pluck('action')->all(),
        );
        $this->assertTrue($entries->every(fn (AuditEntry $entry): bool => $entry->account_id === null && $entry->ip_address === '127.0.0.1'));
    }

    public function test_entries_are_pruned_after_a_year(): void
    {
        foreach ([AuditEntry::RETENTION_DAYS + 1, 10] as $daysAgo) {
            (new AuditEntry)->forceFill(['action' => AuditAction::PasswordChanged, 'created_at' => now()->subDays($daysAgo)])->save();
        }

        $this->assertSame(0, Artisan::call('model:prune', ['--model' => [AuditEntry::class]]));
        $this->assertSame(1, AuditEntry::query()->count());
    }
}
