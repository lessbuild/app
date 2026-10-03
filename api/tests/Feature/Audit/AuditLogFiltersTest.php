<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Enums\AuditAction;
use App\Models\Account;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AuditLogFiltersTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['name' => 'Olive Owner']);
        $this->account = Account::factory()->withMember($this->owner)->create();
        $this->owner->forceFill(['current_account_id' => $this->account->id])->save();
    }

    /**
     * The audit log filters by person, kind and date, ignores values from elsewhere, and exports what it shows.
     */
    public function test_the_audit_log_filters_by_person_kind_and_date_and_exports_what_it_shows(): void
    {
        $other = User::factory()->create(['name' => 'Max Member']);
        $membership = new \App\Models\Membership;
        $membership->forceFill(['account_id' => $this->account->id, 'user_id' => $other->id, 'role' => \App\Enums\AccountRole::Member])->save();
        $this->entry($this->owner, AuditAction::AccountRenamed, ['name' => 'Acme'], now()->subDays(10));
        $this->entry($other, AuditAction::PasswordChanged, [], now()->subDay());
        $this->entry($this->owner, AuditAction::TwoFactorEnabled, [], now());

        $this->actingAs($this->owner)->getJson('/api/app/account/audit-log?category=security')->assertOk()->assertJsonPath('filters.category', 'security')
            ->assertSee('Changed their password')->assertDontSee('Renamed the account');
        $this->actingAs($this->owner)->getJson("/api/app/account/audit-log?category=security&person={$other->id}")->assertOk()
            ->assertSee('Changed their password')->assertDontSee('Turned on two-factor');
        $this->actingAs($this->owner)->getJson('/api/app/account/audit-log?from='.now()->subDays(11)->toDateString().'&to='.now()->subDays(9)->toDateString())->assertOk()
            ->assertSee('Renamed the account')->assertDontSee('Changed their password');
        // Values that don't belong to the account are ignored.
        $this->actingAs($this->owner)->getJson('/api/app/account/audit-log?person='.User::factory()->create()->id.'&category=nope&from=yesterday')->assertOk()
            ->assertJsonPath('filters.person', null)->assertJsonPath('filters.category', null)->assertSee('Renamed the account');

        $csv = $this->actingAs($this->owner)->get('/api/app/account/audit-log/export?category=security')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->streamedContent();
        $this->assertStringContainsString('Changed their password', $csv);
        $this->assertStringContainsString('Sign-in and security', $csv);
        $this->assertStringNotContainsString('Renamed the account', $csv);
    }

    /**
     * Record an audit entry in the account.
     *
     * @param  User  $actor
     * @param  AuditAction  $action
     * @param  array<string, mixed>  $context
     * @param  \Illuminate\Support\Carbon  $at
     * @return void
     */
    private function entry(User $actor, AuditAction $action, array $context, \Illuminate\Support\Carbon $at): void
    {
        $entry = new AuditEntry;
        $entry->forceFill(['account_id' => $this->account->id, 'actor_id' => $actor->id, 'actor_name' => $actor->name, 'actor_email' => $actor->email, 'action' => $action, 'context' => $context, 'created_at' => $at])->save();
    }
}
