<?php

declare(strict_types=1);

namespace Tests\Feature\Accounts;

use App\Domain\Accounts\Enums\AccountRole;
use App\Domain\Accounts\Models\Account;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const CONFIRMED = ['auth.password_confirmed_at' => PHP_INT_MAX];

    public function test_admins_rename_the_account_and_it_is_audited(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->withMember($owner)->create(['name' => 'Acme']);
        $admin = $this->member($account, AccountRole::Admin);

        $this->actingAs($admin)->get('/account/settings')->assertOk()->assertSee('value="Acme"', false)->assertDontSee(__('Delete this account'));
        $this->actingAs($admin)->put('/account/settings', ['name' => '  Acme Corp '])->assertRedirect('/account/settings');

        $this->assertSame('Acme Corp', $account->refresh()->name);
        $entry = AuditEntry::query()->where('action', AuditAction::AccountRenamed)->sole();
        $this->assertSame('Renamed the account from Acme to Acme Corp', $entry->action->describe($entry->context ?? []));
    }

    public function test_members_cannot_change_settings(): void
    {
        $account = Account::factory()->withMember(User::factory()->create())->create();
        $member = $this->member($account, AccountRole::Member);

        $this->actingAs($member)->get('/account/settings')->assertForbidden();
        $this->actingAs($member)->put('/account/settings', ['name' => 'Hijacked'])->assertForbidden();
    }

    public function test_owners_delete_the_account_and_members_move_to_another_account(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->withMember($owner)->create(['name' => 'Doomed']);
        $member = User::factory()->create();
        $home = Account::factory()->withMember($member)->create();
        $account->memberships()->forceCreate(['user_id' => $member->id, 'role' => AccountRole::Member]);
        $member->forceFill(['current_account_id' => $account->id])->save();

        $this->actingAs($owner)->withSession(self::CONFIRMED)->delete('/account/settings', ['confirm_name' => 'doomed'])->assertSessionHasErrorsIn('deleteAccount', 'confirm_name');
        $this->assertNotNull($account->fresh());

        $this->actingAs($owner)->withSession(self::CONFIRMED)->delete('/account/settings', ['confirm_name' => 'Doomed'])->assertRedirect('/dashboard');

        $this->assertNull($account->fresh());
        $this->assertSame($home->id, $member->refresh()->current_account_id);
        $this->assertNull($owner->refresh()->current_account_id);
        $this->actingAs($owner)->get('/dashboard')->assertOk();
    }

    public function test_admins_cannot_delete_the_account(): void
    {
        $account = Account::factory()->withMember(User::factory()->create())->create(['name' => 'Acme']);
        $admin = $this->member($account, AccountRole::Admin);

        $this->actingAs($admin)->withSession(self::CONFIRMED)->delete('/account/settings', ['confirm_name' => 'Acme'])->assertForbidden();
        $this->assertNotNull($account->fresh());
    }

    private function member(Account $account, AccountRole $role): User
    {
        $user = User::factory()->create(['current_account_id' => null]);
        $account->memberships()->forceCreate(['user_id' => $user->id, 'role' => $role]);
        $user->forceFill(['current_account_id' => $account->id])->save();

        return $user;
    }
}
