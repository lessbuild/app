<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Contracts\Monitoring\DnsResolver;
use App\Enums\AccountRole;
use App\Enums\AuditAction;
use App\Models\Account;
use App\Models\AuditEntry;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(RequirePassword::class);
        $this->owner = User::factory()->create(['email' => 'olive@acme.com']);
        $this->account = Account::factory()->withMember($this->owner)->create(['name' => 'Acme']);
        $this->owner->forceFill(['current_account_id' => $this->account->id])->save();
    }

    public function test_owners_cant_save_rules_that_would_lock_them_out(): void
    {
        $this->actingAs($this->owner)->get('/account/security')->assertOk()->assertSee(route('sso.callback'))->assertSee('127.0.0.1');

        $this->save(['allowed_ip_ranges' => "203.0.113.0/24\nnot-an-ip"])->assertSessionHasErrors('allowed_ip_ranges');
        $this->save(['allowed_ip_ranges' => '203.0.113.0/24'])->assertSessionHasErrors('allowed_ip_ranges');
        $this->save(['allowed_email_domains' => 'other.com'])->assertSessionHasErrors('allowed_email_domains');
        $this->save(['require_two_factor' => '1'])->assertSessionHasErrors('require_two_factor');
        $this->save(['sso_enforced' => '1'])->assertSessionHasErrors('sso_enforced');
        $this->save(['sso_issuer' => 'https://login.acme.com', 'sso_client_id' => 'bp', 'sso_client_secret' => 's3cret', 'sso_enforced' => '1'])->assertSessionHasErrors('sso_enforced');

        $this->save(['allowed_ip_ranges' => "127.0.0.0/8\n2001:db8::/32", 'allowed_email_domains' => 'ACME.com, acme.co.uk', 'session_idle_minutes' => '30'])->assertRedirect('/account/security');
        $account = $this->account->refresh();
        $this->assertSame([['127.0.0.0/8', '2001:db8::/32'], ['acme.com', 'acme.co.uk'], 30], [$account->allowed_ip_ranges, $account->allowed_email_domains, $account->session_idle_minutes]);
        $this->assertStringContainsString('IP ranges', (string) data_get(AuditEntry::query()->where('action', AuditAction::SecurityRulesChanged)->sole()->context, 'changes'));

        $member = $this->member('max@acme.com', AccountRole::Member);
        $this->actingAs($member)->get('/account/security')->assertForbidden();
    }

    public function test_the_rules_apply_to_every_signed_in_request_but_never_trap_anyone(): void
    {
        $member = $this->member('max@acme.com', AccountRole::Member);
        $this->account->forceFill(['allowed_ip_ranges' => ['203.0.113.0/24']])->save();
        $this->actingAs($member->fresh() ?? $member)->get('/dashboard')->assertForbidden();
        $this->actingAs($member->fresh() ?? $member)->get('/settings/profile')->assertOk();
        $this->actingAs($member->fresh() ?? $member)->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('/dashboard')->assertOk();

        $this->account->forceFill(['allowed_ip_ranges' => null, 'require_two_factor' => true])->save();
        $this->actingAs($member->fresh() ?? $member)->get('/dashboard')->assertRedirect('/settings/security');
        $this->actingAs($member->fresh() ?? $member)->get('/settings/security')->assertOk()->assertSee('requires two-factor authentication');

        $this->account->forceFill(['require_two_factor' => false, 'session_idle_minutes' => 30])->save();
        $this->actingAs($member->fresh() ?? $member)->withSession(['account.activity.'.$this->account->id => now()->subMinutes(31)->getTimestamp()])->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
        $this->actingAs($member->fresh() ?? $member)->withSession(['account.activity.'.$this->account->id => now()->subMinutes(5)->getTimestamp()])->get('/dashboard')->assertOk();

        $this->configureSso(['sso_enforced' => true]);
        $this->actingAs($member->fresh() ?? $member)->get('/dashboard')->assertRedirect('/sso/verify');
        $this->actingAs($member->fresh() ?? $member)->withSession(['sso.verified.'.$this->account->id => true])->get('/dashboard')->assertOk();
    }

    public function test_invitations_are_limited_to_the_allowed_domains(): void
    {
        $this->account->forceFill(['allowed_email_domains' => ['acme.com']])->save();
        $this->actingAs($this->owner)->post('/account/invitations', ['email' => 'eve@elsewhere.com', 'role' => 'member'])->assertSessionHasErrors('email');
        $this->actingAs($this->owner)->post('/account/invitations', ['email' => 'max@acme.com', 'role' => 'member'])->assertSessionHasNoErrors();
    }

    public function test_members_sign_in_through_the_identity_provider(): void
    {
        $this->configureSso();
        $this->fakeProvider('olive@acme.com');
        $this->get('/login')->assertSee(route('sso.login'));

        $this->post('/login/sso', ['email' => 'nobody@acme.com'])->assertSessionHasErrors('email');
        $redirect = $this->post('/login/sso', ['email' => 'Olive@acme.com'])->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://login.acme.com/authorize?', (string) $redirect);
        parse_str((string) parse_url((string) $redirect, PHP_URL_QUERY), $query);
        $this->assertSame(['bp-client', route('sso.callback'), 'S256'], [$query['client_id'], $query['redirect_uri'], $query['code_challenge_method']]);

        $this->get('/sso/callback?code=abc&state=wrong')->assertRedirect('/login');
        $this->assertGuest();

        $this->get('/sso/callback?code=abc&state='.$this->state($this->post('/login/sso', ['email' => 'olive@acme.com'])->headers->get('Location')))->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($this->owner);
        $this->assertTrue(session('sso.verified.'.$this->account->id));
        $this->assertSame(1, AuditEntry::query()->where('action', AuditAction::SsoSignedIn)->count());
        Http::assertSent(fn ($request): bool => $request->url() === 'https://login.acme.com/token' && $request['code'] === 'abc' && strlen((string) $request['code_verifier']) === 96);
    }

    public function test_the_provider_must_be_public_and_vouch_for_a_member_with_an_allowed_domain(): void
    {
        $this->configureSso(['allowed_email_domains' => ['acme.com']]);
        $this->fakeProvider('stranger@acme.com');
        $this->get('/sso/callback?code=abc&state='.$this->state($this->post('/login/sso', ['email' => 'olive@acme.com'])->headers->get('Location')))->assertRedirect('/login')->assertSessionHasErrors('social');
        $this->assertGuest();

        $this->mock(DnsResolver::class)->shouldReceive('addresses')->andReturn(['10.0.0.8']);
        $this->post('/login/sso', ['email' => 'olive@acme.com'])->assertSessionHasErrors('email');
    }

    /**
     * Read the state from the address we sent someone to at their identity provider.
     *
     * @param  string|null  $location
     * @return string
     */
    private function state(?string $location): string
    {
        parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);

        return is_string($query['state'] ?? null) ? $query['state'] : '';
    }

    /**
     * Save the security form as the owner, filling in the defaults.
     *
     * @param  array<string, string>  $fields
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function save(array $fields): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->owner)->from('/account/security')->put('/account/security', ['require_two_factor' => '0', 'sso_enforced' => '0', ...$fields]);
    }

    /**
     * Add a member to the account.
     *
     * @param  string  $email
     * @param  AccountRole  $role
     * @return User
     */
    private function member(string $email, AccountRole $role): User
    {
        $user = User::factory()->create(['email' => $email]);
        $membership = new Membership;
        $membership->forceFill(['account_id' => $this->account->id, 'user_id' => $user->id, 'role' => $role])->save();
        $user->forceFill(['current_account_id' => $this->account->id])->save();

        return $user;
    }

    /**
     * Set up single sign-on for the account.
     *
     * @param  array<string, mixed>  $extra
     * @return void
     */
    private function configureSso(array $extra = []): void
    {
        $this->account->forceFill(['sso_issuer' => 'https://login.acme.com', 'sso_client_id' => 'bp-client', 'sso_client_secret' => 'bp-secret', ...$extra])->save();
    }

    /**
     * Fake the identity provider, vouching for an email address, on a public address.
     *
     * @param  string  $email
     * @return void
     */
    private function fakeProvider(string $email): void
    {
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->andReturn(['93.184.216.34']);
        Http::fake([
            'https://login.acme.com/.well-known/openid-configuration' => Http::response(['authorization_endpoint' => 'https://login.acme.com/authorize', 'token_endpoint' => 'https://login.acme.com/token', 'userinfo_endpoint' => 'https://login.acme.com/userinfo']),
            'https://login.acme.com/token' => Http::response(['access_token' => 'tok']),
            'https://login.acme.com/userinfo' => Http::response(['email' => $email, 'email_verified' => true]),
        ]);
    }
}
