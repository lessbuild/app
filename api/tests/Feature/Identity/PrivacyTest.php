<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Actions\ApiTokens\CreateApiToken;
use App\Data\ApiTokens\CreateApiTokenData;
use App\Enums\AccountRole;
use App\Enums\ApiScope;
use App\Enums\AuditAction;
use App\Enums\SocialProvider;
use App\Events\Users\PasswordChanged;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\AuditEntry;
use App\Models\SocialIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PrivacyTest extends TestCase
{
    use RefreshDatabase;

    private const CONFIRMED = ['auth.password_confirmed_at' => PHP_INT_MAX];

    /**
     * The export holds the person's data but no secrets.
     */
    public function test_the_export_contains_personal_data_but_no_secrets(): void
    {
        $user = User::factory()->create(['name' => 'Ada Lovelace', 'password' => 'secret-password-123']);
        $user->forceFill(['two_factor_secret' => encrypt('TOTPSECRET'), 'two_factor_confirmed_at' => now()])->save();
        Account::factory()->withMember($user)->create(['name' => 'Acme']);
        (new SocialIdentity)->forceFill(['user_id' => $user->id, 'provider' => SocialProvider::GitHub, 'provider_user_id' => 'gh-1', 'email' => 'ada@github.example'])->save();
        app(CreateApiToken::class)->handle($user, Account::query()->sole(), new CreateApiTokenData('CI', [ApiScope::AccountRead], 30));
        PasswordChanged::dispatch($user);

        $response = $this->actingAs($user)->get('/api/app/settings/privacy/export')->assertOk()->assertDownload();
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

    /**
     * Deleting a user removes their solo accounts, leaves shared ones and erases their personal data.
     */
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

        $this->actingAs($user)->getJson('/api/app/settings/privacy')->assertOk()->assertJsonPath('toDelete', ['Solo'])->assertJsonPath('toLeave', ['Shared'])->assertJsonPath('blockedBy', []);

        $this->actingAs($user)->withSession(self::CONFIRMED)->deleteJson('/api/app/settings/privacy/user', ['confirm_email' => 'wrong@example.com'])->assertJsonValidationErrors('confirm_email');
        $this->assertNotNull($user->fresh());

        $this->actingAs($user)->withSession(self::CONFIRMED)->deleteJson('/api/app/settings/privacy/user', ['confirm_email' => 'ADA@example.com'])
            ->assertOk()->assertJsonPath('redirect', '/login')
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

    /**
     * The only owner of a shared account can't delete their user.
     */
    public function test_a_sole_owner_of_a_shared_account_cannot_delete_their_user(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->withMember($user)->create(['name' => 'Team']);
        $account->memberships()->forceCreate(['user_id' => User::factory()->create()->id, 'role' => AccountRole::Member]);

        $this->actingAs($user)->getJson('/api/app/settings/privacy')->assertOk()->assertJsonPath('blockedBy', ['Team']);
        $this->actingAs($user)->withSession(self::CONFIRMED)->deleteJson('/api/app/settings/privacy/user', ['confirm_email' => $user->email])->assertJsonValidationErrors('confirm_email');

        $this->assertNotNull($user->fresh());
        $this->assertNotNull($account->fresh());
    }

    /**
     * Deleting a user needs a recent confirmation of who they are.
     */
    public function test_deletion_needs_a_recent_password_confirmation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->deleteJson('/api/app/settings/privacy/user', ['confirm_email' => $user->email])->assertStatus(423);
        $this->assertNotNull($user->fresh());
    }
}
