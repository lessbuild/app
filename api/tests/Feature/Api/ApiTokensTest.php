<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Actions\ApiTokens\CreateApiToken;
use App\Data\ApiTokens\CreateApiTokenData;
use App\Enums\AccountRole;
use App\Enums\ApiScope;
use App\Enums\AuditAction;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class ApiTokensTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->account = Account::factory()->withMember($this->owner)->create(['name' => 'Acme']);
    }

    /**
     * Owners create a token after confirming it's them; its value is returned once, and the creation is audited.
     */
    public function test_owners_create_a_token_that_is_shown_once_and_audited(): void
    {
        $confirmed = ['auth.password_confirmed_at' => time()];

        $this->actingAs($this->owner)->postJson('/api/app/account/api-tokens', ['name' => 'CI', 'scopes' => ['deploy:write'], 'expires' => '30'])->assertStatus(423);

        $plainText = (string) $this->actingAs($this->owner)->withSession($confirmed)
            ->postJson('/api/app/account/api-tokens', ['name' => 'CI', 'scopes' => ['deploy:write', 'account:read'], 'expires' => '30'])
            ->assertCreated()->assertJsonPath('token.name', 'CI')->json('token.value');
        $this->assertStringContainsString('|bpk_', $plainText);

        $token = ApiToken::query()->sole();
        $this->assertSame($this->account->id, $token->account_id);
        $this->assertSame(['account:read', 'deploy:read', 'deploy:write'], $token->abilities);
        $this->assertTrue($token->expires_at?->between(now()->addDays(29), now()->addDays(31)));
        $this->assertNotSame($plainText, $token->getAttributes()['token']);
        $this->assertTrue(AuditEntry::query()->where('action', AuditAction::ApiTokenCreated)->where('account_id', $this->account->id)->exists());

        $list = $this->actingAs($this->owner)->getJson('/api/app/account/api-tokens')->assertOk()->assertJsonPath('tokens.0.name', 'CI');
        $this->assertStringNotContainsString($plainText, (string) $list->getContent());
    }

    /**
     * Token requests need a name, known scopes and one of the lifetimes on offer.
     */
    public function test_token_requests_are_validated(): void
    {
        $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/app/account/api-tokens', ['name' => '', 'scopes' => ['root:everything'], 'expires' => '9999'])
            ->assertJsonValidationErrors(['name', 'scopes.0', 'expires']);
    }

    /**
     * A token calls the API inside its account and only within its scopes.
     */
    public function test_a_token_calls_the_api_inside_its_account_and_only_within_its_scopes(): void
    {
        $readOnly = $this->token([ApiScope::AccountRead]);
        $deployOnly = $this->token([ApiScope::DeployRead]);

        $this->api($readOnly)->assertOk()->assertJsonPath('data.id', $this->account->id)->assertJsonPath('data.name', 'Acme');
        $this->assertNotNull(ApiToken::query()->findOrFail((int) strtok($readOnly, '|'))->last_used_at);

        $this->api($deployOnly)->assertForbidden();
        $this->api('1|not-a-real-token')->assertUnauthorized();
        $this->api(null)->assertUnauthorized();
    }

    /**
     * A browser session isn't an API credential.
     */
    public function test_a_browser_session_is_not_an_api_credential(): void
    {
        $this->actingAs($this->owner)->getJson('/api/v1/account')->assertUnauthorized();
    }

    /**
     * Tokens stop working when their creator loses access, or when they expire.
     */
    public function test_tokens_stop_working_when_their_creator_loses_access_or_they_expire(): void
    {
        $token = $this->token([ApiScope::AccountRead]);
        $this->api($token)->assertOk();

        $admin = User::factory()->create();
        $membership = $this->account->memberships()->forceCreate(['user_id' => $admin->id, 'role' => AccountRole::Admin]);
        $adminToken = $this->token([ApiScope::AccountRead], $admin);
        $this->api($adminToken)->assertOk();
        $membership->forceFill(['role' => AccountRole::Member])->save();
        $this->api($adminToken)->assertForbidden();
        $membership->delete();
        $this->api($adminToken)->assertForbidden();

        ApiToken::query()->whereKey((int) strtok($token, '|'))->update(['expires_at' => now()->subMinute()]);
        $this->api($token)->assertUnauthorized();
    }

    /**
     * Only those who manage tokens see them, and tokens are revoked within the account.
     */
    public function test_only_token_managers_see_the_page_and_tokens_are_revoked_within_the_account(): void
    {
        $member = User::factory()->create();
        $this->account->memberships()->forceCreate(['user_id' => $member->id, 'role' => AccountRole::Member]);
        $member->forceFill(['current_account_id' => $this->account->id])->save();
        $this->actingAs($member)->getJson('/api/app/account/api-tokens')->assertForbidden();

        $token = $this->token([ApiScope::AccountRead]);
        $id = (int) strtok($token, '|');
        $this->actingAs($member)->deleteJson("/api/app/account/api-tokens/{$id}")->assertForbidden();

        $stranger = User::factory()->create();
        Account::factory()->withMember($stranger)->create();
        $this->actingAs($stranger)->deleteJson("/api/app/account/api-tokens/{$id}")->assertNotFound();

        $this->actingAs($this->owner)->deleteJson("/api/app/account/api-tokens/{$id}")->assertOk()->assertJsonPath('redirect', '/account/api-tokens');
        $this->assertNull(ApiToken::query()->find($id));
        $this->api($token)->assertUnauthorized();
    }

    /**
     * Create a token with these scopes, as the owner or someone else.
     *
     * @param  list<ApiScope>  $scopes
     * @param  User|null  $user
     * @return string The token's value.
     */
    private function token(array $scopes, ?User $user = null): string
    {
        return app(CreateApiToken::class)->handle($user ?? $this->owner, $this->account, new CreateApiTokenData('test', $scopes, 30))->plainText;
    }

    /**
     * Call /api/v1/account with a token, or with none.
     *
     * @param  string|null  $token
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function api(?string $token): TestResponse
    {
        // Each call is a fresh API request: forget whoever the previous one authenticated.
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return ($token === null ? $this : $this->withToken($token))->getJson('/api/v1/account');
    }
}
