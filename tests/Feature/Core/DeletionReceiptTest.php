<?php

namespace Tests\Feature\Core;

use App\Core\Data\Deletion\ProductDeletionTarget;
use App\Core\Models\DeletionRequest;
use App\Core\Models\DeletionStep;
use App\Core\Models\PlatformAuthSession;
use App\Core\Models\PlatformUser;
use App\Core\Services\Auth\PlatformTwoFactorCredentials;
use App\Core\Services\Deletion\DeletionPlanner;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DeletionReceiptTest extends TestCase
{
    private PlatformUser $actor;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.core.database' => ':memory:']);
        DB::purge('core');
        Artisan::call('platform:migrate', ['module' => 'core']);
        $this->actor = $this->actor();
    }

    public function test_unauthorized_or_mismatched_receipt_does_not_reveal_status_or_retained_records(): void
    {
        [$deletion, $token] = $this->deletion('account', 'blocked', ['Private retained marker']);
        $progressUrl = route('platform.deletions.progress', $deletion->idempotency_key);

        $this->get($progressUrl)->assertOk()
            ->assertSee('Open deletion receipt')
            ->assertDontSee('Private retained marker')
            ->assertDontSee('Needs attention');

        $this->withCookie($this->cookieName($deletion), str_repeat('b', 64))
            ->get($progressUrl)->assertOk()
            ->assertSee('Open deletion receipt')
            ->assertDontSee('Private retained marker')
            ->assertDontSee('Needs attention');
    }

    public function test_invalid_recovery_key_does_not_flash_the_submitted_secret(): void
    {
        [$deletion] = $this->deletion('workspace', 'blocked');

        $response = $this->post(route('platform.deletions.recover', $deletion->idempotency_key), [
            'token' => str_repeat('b', 64),
        ]);

        $response->assertRedirect(route('platform.deletions.progress', $deletion->idempotency_key))
            ->assertSessionHasErrors('token')
            ->assertSessionMissing('_old_input');
        $response->assertCookieMissing($this->cookieName($deletion));
    }

    public function test_valid_recovery_sets_scoped_cookie_and_unlocks_receipt(): void
    {
        [$deletion, $token] = $this->deletion('account', 'blocked', ['Receipt retention marker']);

        $response = $this->post(route('platform.deletions.recover', $deletion->idempotency_key), ['token' => $token]);

        $response->assertRedirect(route('platform.deletions.progress', $deletion->idempotency_key));
        $cookie = collect($response->headers->getCookies())->first(fn ($item): bool => $item->getName() === $this->cookieName($deletion));
        $this->assertNotNull($cookie);
        $this->assertSame('/platform/deletions', $cookie->getPath());
        $this->assertTrue($cookie->isHttpOnly());

        $this->withCookie($this->cookieName($deletion), $token)
            ->get(route('platform.deletions.progress', $deletion->idempotency_key))
            ->assertOk()
            ->assertSee('Needs attention')
            ->assertSee('Receipt retention marker');
    }

    public function test_account_receipt_remains_available_after_account_logout_and_session_revocation(): void
    {
        [$deletion, $token] = $this->deletion('account', 'pending');
        $this->actor->forceFill(['status' => 'deleting'])->save();
        PlatformAuthSession::query()->where('user_id', $this->actor->getKey())->update(['revoked_at' => now()]);

        $this->withCookie($this->cookieName($deletion), $token)
            ->get(route('platform.deletions.progress', $deletion->idempotency_key))
            ->assertOk()
            ->assertSee('Account deletion')
            ->assertSee('In progress');
    }

    public function test_retry_requires_the_receipt_capability_and_retries_only_its_saved_request(): void
    {
        [$target, $targetToken] = $this->deletion('account', 'blocked');
        [$unrelated, $unrelatedToken] = $this->deletion('workspace', 'blocked');
        $targetStep = $this->step($target, 'blocked');
        $unrelatedStep = $this->step($unrelated, 'blocked');
        $url = route('platform.deletions.retry', $target->idempotency_key);

        $this->post($url)->assertNotFound();
        $this->assertSame('blocked', $target->refresh()->status);
        $this->assertSame('blocked', $unrelated->refresh()->status);

        $this->withCookie($this->cookieName($target), $targetToken)->post($url)
            ->assertRedirect(route('platform.deletions.progress', $target->idempotency_key));

        $this->assertSame('pending', $target->refresh()->status);
        $this->assertSame('pending', $targetStep->refresh()->status);
        $this->assertSame('blocked', $unrelated->refresh()->status);
        $this->assertSame('blocked', $unrelatedStep->refresh()->status);
        $this->assertSame(2, DeletionRequest::query()->count());
    }

    public function test_store_requires_fresh_password_and_never_flashes_password_or_code(): void
    {
        $password = 'correct horse battery staple';
        $this->actor->forceFill(['password' => Hash::make($password)])->save();
        $session = $this->platformSession();
        $plan = app(DeletionPlanner::class)->plan($this->actor);
        $key = (string) Str::uuid();
        $token = str_repeat('c', 64);

        $response = $this->actingAs($this->actor, 'platform')->withSession(['platform.auth.session_id' => $session->getKey()])
            ->withCookie('deletion_receipt_'.$key, $token)
            ->post(route('platform.deletions.account.store'), [
                'idempotency_key' => $key,
                'fingerprint' => $plan['fingerprint'],
                'confirmation' => $this->actor->email,
                'understood' => '1',
                'current_password' => 'wrong password secret',
                'code' => 'submitted auth secret',
            ]);

        $response->assertRedirect(route('platform.deletions.account.create'))
            ->assertSessionHasErrors('current_password')
            ->assertSessionMissing('_old_input');
        $this->assertSame(0, DeletionRequest::query()->count());
    }

    public function test_store_requires_valid_mfa_and_does_not_flash_code_or_recovery_key(): void
    {
        $password = 'correct horse battery staple';
        $this->actor->forceFill([
            'password' => Hash::make($password),
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => app(PlatformTwoFactorCredentials::class)->encryptRecoveryHashes([]),
            'two_factor_confirmed_at' => now(),
        ])->save();
        $session = $this->platformSession();
        $plan = app(DeletionPlanner::class)->plan($this->actor);
        $key = (string) Str::uuid();
        $token = str_repeat('d', 64);

        $response = $this->actingAs($this->actor, 'platform')->withSession(['platform.auth.session_id' => $session->getKey()])
            ->withCookie('deletion_receipt_'.$key, $token)
            ->post(route('platform.deletions.account.store'), [
                'idempotency_key' => $key,
                'fingerprint' => $plan['fingerprint'],
                'confirmation' => $this->actor->email,
                'understood' => '1',
                'current_password' => $password,
                'code' => 'not-a-valid-code-secret',
            ]);

        $response->assertRedirect(route('platform.deletions.account.create'))
            ->assertSessionHasErrors('code')
            ->assertSessionMissing('_old_input');
        $this->assertSame(0, DeletionRequest::query()->count());
    }

    private function actor(): PlatformUser
    {
        return PlatformUser::query()->forceCreate([
            'name' => 'Receipt owner',
            'email' => 'receipt-owner@example.test',
            'email_normalized' => 'receipt-owner@example.test',
            'status' => 'active',
        ]);
    }

    /** @return array{DeletionRequest, string} */
    private function deletion(string $kind, string $status, array $retained = []): array
    {
        $token = str_repeat($kind === 'account' ? 'a' : 'e', 64);
        $key = (string) Str::uuid();
        $deletion = DeletionRequest::query()->create([
            'actor_id' => $this->actor->getKey(),
            'kind' => $kind,
            'target_id' => $this->actor->getKey(),
            'workspace_ids' => [],
            'identity_bindings' => [],
            'intent_hash' => str_repeat('1', 64),
            'receipt_token_hash' => hash('sha256', $token),
            'idempotency_key' => $key,
            'phase' => 'purge',
            'status' => $status,
            'retained' => $retained,
            'accepted_at' => now(),
        ]);

        return [$deletion, $token];
    }

    private function step(DeletionRequest $deletion, string $status): DeletionStep
    {
        $kind = $deletion->kind;
        $target = new ProductDeletionTarget(
            product: 'monitor',
            kind: $kind,
            sourceId: 'native-'.$kind.'-'.$deletion->getKey(),
            actorSourceId: 'native-user-1',
            canonicalId: (string) $deletion->target_id,
            actorId: (string) $deletion->actor_id,
            sourceWorkspaceIds: [],
        );

        return $deletion->steps()->create([
            'product' => 'monitor',
            'kind' => $kind,
            'source_id' => $target->sourceId,
            'target' => $target->toArray(),
            'payload_hash' => hash('sha256', json_encode($target->toArray(), JSON_THROW_ON_ERROR)),
            'phase' => 'purge',
            'status' => $status,
            'attempts' => 1,
        ]);
    }

    private function platformSession(): PlatformAuthSession
    {
        return PlatformAuthSession::query()->create(['user_id' => $this->actor->getKey(), 'last_seen_at' => now()]);
    }

    private function cookieName(DeletionRequest $deletion): string
    {
        return 'deletion_receipt_'.$deletion->idempotency_key;
    }
}
