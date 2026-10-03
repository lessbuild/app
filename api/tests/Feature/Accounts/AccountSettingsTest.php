<?php

declare(strict_types=1);

namespace Tests\Feature\Accounts;

use App\Enums\AccountRole;
use App\Enums\AuditAction;
use App\Models\Account;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const CONFIRMED = ['auth.password_confirmed_at' => PHP_INT_MAX];

    /**
     * Admins rename the account, and the change is audited.
     */
    public function test_admins_rename_the_account_and_it_is_audited(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->withMember($owner)->create(['name' => 'Acme']);
        $admin = $this->member($account, AccountRole::Admin);

        $this->actingAs($admin)->getJson('/api/app/account/settings')->assertOk()->assertJsonPath('account.name', 'Acme')->assertJsonPath('canDelete', false);
        $this->actingAs($admin)->putJson('/api/app/account/settings', ['name' => '  Acme Corp '])->assertOk()->assertJsonPath('redirect', '/account/settings');

        $this->assertSame('Acme Corp', $account->refresh()->name);
        $entry = AuditEntry::query()->where('action', AuditAction::AccountRenamed)->sole();
        $this->assertSame('Renamed the account from Acme to Acme Corp', $entry->action->describe($entry->context ?? []));
    }

    /**
     * Members can't see or change the account's settings.
     */
    public function test_members_cannot_change_settings(): void
    {
        $account = Account::factory()->withMember(User::factory()->create())->create();
        $member = $this->member($account, AccountRole::Member);

        $this->actingAs($member)->getJson('/api/app/account/settings')->assertForbidden();
        $this->actingAs($member)->putJson('/api/app/account/settings', ['name' => 'Hijacked'])->assertForbidden();
    }

    /**
     * Owners delete the account after typing its name; members move to another of their accounts.
     */
    public function test_owners_delete_the_account_and_members_move_to_another_account(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->withMember($owner)->create(['name' => 'Doomed']);
        $member = User::factory()->create();
        $home = Account::factory()->withMember($member)->create();
        $account->memberships()->forceCreate(['user_id' => $member->id, 'role' => AccountRole::Member]);
        $member->forceFill(['current_account_id' => $account->id])->save();

        $this->actingAs($owner)->withSession(self::CONFIRMED)->deleteJson('/api/app/account/settings', ['confirm_name' => 'doomed'])->assertJsonValidationErrors('confirm_name');
        $this->assertNotNull($account->fresh());

        $this->actingAs($owner)->withSession(self::CONFIRMED)->deleteJson('/api/app/account/settings', ['confirm_name' => 'Doomed'])->assertOk()->assertJsonPath('redirect', '/dashboard');

        $this->assertNull($account->fresh());
        $this->assertSame($home->id, $member->refresh()->current_account_id);
        $this->assertNull($owner->refresh()->current_account_id);
        $this->actingAs($owner)->getJson('/api/app/dashboard')->assertOk()->assertJsonPath('account', null);
    }

    /**
     * Admins can't delete the account.
     */
    public function test_admins_cannot_delete_the_account(): void
    {
        $account = Account::factory()->withMember(User::factory()->create())->create(['name' => 'Acme']);
        $admin = $this->member($account, AccountRole::Admin);

        $this->actingAs($admin)->withSession(self::CONFIRMED)->deleteJson('/api/app/account/settings', ['confirm_name' => 'Acme'])->assertForbidden();
        $this->assertNotNull($account->fresh());
    }

    /**
     * Add a new person to an account in a role, looking at it.
     *
     * @param  Account  $account
     * @param  AccountRole  $role
     * @return User
     */
    private function member(Account $account, AccountRole $role): User
    {
        $user = User::factory()->create(['current_account_id' => null]);
        $account->memberships()->forceCreate(['user_id' => $user->id, 'role' => $role]);
        $user->forceFill(['current_account_id' => $account->id])->save();

        return $user;
    }
}
