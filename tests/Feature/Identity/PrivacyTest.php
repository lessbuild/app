<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domain\Accounts\Enums\AccountRole;
use App\Domain\Accounts\Models\Account;
use App\Domain\Api\Actions\CreateApiToken;
use App\Domain\Api\Data\CreateApiTokenData;
use App\Domain\Api\Enums\ApiScope;
use App\Domain\Api\Models\ApiToken;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Identity\Enums\SocialProvider;
use App\Domain\Identity\Events\PasswordChanged;
use App\Domain\Identity\Models\SocialIdentity;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PrivacyTest extends TestCase
{
    use RefreshDatabase;

    private const CONFIRMED = ['auth.password_confirmed_at' => PHP_INT_MAX];

    public function test_the_export_contains_personal_data_but_no_secrets(): void
    {
        $user = User::factory()->create(['name' => 'Ada Lovelace', 'password' => 'secret-password-123']);
        $user->forceFill(['two_factor_secret' => encrypt('TOTPSECRET'), 'two_factor_confirmed_at' => now()])->save();
        Account::factory()->withMember($user)->create(['name' => 'Acme']);
        (new SocialIdentity)->forceFill(['user_id' => $user->id, 'provider' => SocialProvider::GitHub, 'provider_user_id' => 'gh-1', 'email' => 'ada@github.example'])->save();
        app(CreateApiToken::class)->handle($user, Account::query()->sole(), new CreateApiTokenData('CI', [ApiScope::AccountRead], 30));
        PasswordChanged::dispatch($user);

        $response = $this->actingAs($user)->get('/settings/privacy/export')->assertOk()->assertDownload();
        $json = $response->streamedContent();
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('Ada Lovelace', $data['profile']['name']);
        $this->assertTrue($data['profile']['two_factor_enabled']);
        $this->assertSame('Acme', $data['accounts'][0]['account_name']);
        $this->assertSame('github', $data['connected_providers'][0]['provider']);
        $this->assertSame(['account:read'], $data['api_tokens'][0]['scopes']);
        $this->assertContains('password.changed', array_column($data['activity'], 'action'));
        foreach ([(string) $user->getAuthPassword(), 'TOTPSECRET', (string) ApiToken::query()->value('token'), 'gh-1'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_deleting_a_user_removes_solo_accounts_leaves_shared_ones_and_erases_personal_data(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        $solo = Account::factory()->withMember($user)->create(['name' => 'Solo']);
        $teammate = User::factory()->create();
        $shared = Account::factory()->withMember($teammate)->create(['name' => 'Shared']);
        $shared->memberships()->forceCreate(['user_id' => $user->id, 'role' => AccountRole::Admin]);
        (new SocialIdentity)->forceFill(['user_id' => $user->id, 'provider' => SocialProvider::GitHub, 'provider_user_id' => 'gh-1'])->save();
        app(CreateApiToken::class)->handle($user, $shared, new CreateApiTokenData('CI', [ApiScope::AccountRead], 30));
        PasswordChanged::dispatch($user);

        $this->actingAs($user)->get('/settings/privacy')->assertOk()->assertSee('Solo')->assertSee('Shared')->assertSee(__('Delete my user account'));

        $this->actingAs($user)->withSession(self::CONFIRMED)->delete('/settings/privacy/user', ['confirm_email' => 'wrong@example.com'])->assertSessionHasErrorsIn('deleteUser', 'confirm_email');
        $this->assertNotNull($user->fresh());

        $this->actingAs($user)->withSession(self::CONFIRMED)->delete('/settings/privacy/user', ['confirm_email' => 'ADA@example.com'])
            ->assertRedirect('/login')
            ->assertCookieExpired(auth()->guard('web')->getRecallerName());

        $this->assertGuest();
        $this->assertNull($user->fresh());
        $this->assertNull(Account::query()->find($solo->id));
        $this->assertNotNull($shared->fresh());
        $this->assertSame([$teammate->id], $shared->memberships()->pluck('user_id')->all());
        $this->assertSame(0, SocialIdentity::query()->count());
        $this->assertSame(0, ApiToken::query()->count());
        $this->assertFalse(AuditEntry::query()->whereNull('account_id')->exists(), 'Personal audit entries are erased.');
        $left = AuditEntry::query()->where('account_id', $shared->id)->where('action', AuditAction::MemberRemoved)->sole();
        $this->assertSame('Left the account', $left->action->describe($left->context ?? []));
    }

    public function test_a_sole_owner_of_a_shared_account_cannot_delete_their_user(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->withMember($user)->create(['name' => 'Team']);
        $account->memberships()->forceCreate(['user_id' => User::factory()->create()->id, 'role' => AccountRole::Member]);

        $this->actingAs($user)->get('/settings/privacy')->assertOk()->assertSee('Team')->assertDontSee(__('Delete my user account'));
        $this->actingAs($user)->withSession(self::CONFIRMED)->delete('/settings/privacy/user', ['confirm_email' => $user->email])->assertSessionHasErrorsIn('deleteUser', 'confirm_email');

        $this->assertNotNull($user->fresh());
        $this->assertNotNull($account->fresh());
    }

    public function test_deletion_needs_a_recent_password_confirmation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->delete('/settings/privacy/user', ['confirm_email' => $user->email])->assertRedirect('/user/confirm-password');
        $this->assertNotNull($user->fresh());
    }
}
