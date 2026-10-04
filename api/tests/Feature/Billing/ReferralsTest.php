<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Contracts\PaymentProvider;
use App\Models\Account;
use App\Models\BillingAccount;
use App\Models\Referral;
use App\Models\ReferralCredit;
use App\Models\User;
use App\Services\Billing\Referrals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Fakes\FakePaymentProvider;
use Tests\TestCase;

final class ReferralsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The fake Stripe.
     *
     * @var FakePaymentProvider
     */
    private FakePaymentProvider $stripe;

    /**
     * Swap Stripe for the fake, and keep sign-up open.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->stripe = new FakePaymentProvider;
        $this->app->instance(PaymentProvider::class, $this->stripe);
        config(['billing.referral_credit_cents' => 2000, 'platform.registration.open' => true]);
    }

    /**
     * Check the whole referral: the link sets a cookie, sign-up records the referral, and when the new account pays
     * both sides get credit (the referrer's once they have a Stripe customer).
     *
     * @return void
     */
    public function test_a_referral_earns_both_accounts_credit_once_the_new_account_pays(): void
    {
        $referrerOwner = User::factory()->create();
        $referrer = Account::factory()->withMember($referrerOwner)->create(['name' => 'Acme']);
        $page = $this->actingAs($referrerOwner)->getJson('/api/app/account/billing')->assertOk()->assertJsonPath('referrals.credit_cents', 2000);
        $code = (string) $referrer->refresh()->referral_code;
        $this->assertSame(10, strlen($code));
        $page->assertJsonPath('referrals.link', route('referrals.show', $code));
        auth()->logout();

        $this->get('/r/unknowncode')->assertRedirect(route('register'))->assertCookieMissing('bp_referral');
        $this->get("/r/{$code}")->assertRedirect(route('register'))->assertCookie('bp_referral', $code, false);
        $this->postJson('/api/app/auth/register', ['name' => 'Nia New', 'email' => 'nia@example.test', 'password' => 'correct-horse-battery-9', 'password_confirmation' => 'correct-horse-battery-9', 'referral' => $code])->assertCreated();
        $newcomer = User::query()->where('email', 'nia@example.test')->sole();
        $referral = Referral::query()->sole();
        $this->assertSame([$referrer->id, $newcomer->id], [$referral->referrer_account_id, $referral->referred_user_id]);
        $referred = Account::query()->findOrFail($referral->referred_account_id);

        // Paying qualifies it once: the new account has a Stripe customer, so its credit goes on at once.
        $this->stripe->subscriptions['sub_9'] = [];
        foreach (['evt_1', 'evt_2'] as $event) {
            $this->webhook($event, 'checkout.session.completed', ['client_reference_id' => $referred->id, 'customer' => 'cus_new', 'subscription' => 'sub_9'])->assertOk();
        }
        $this->assertNotNull($referral->refresh()->qualified_at);
        $this->assertSame(2, ReferralCredit::query()->count());
        $this->assertSame([['customer' => 'cus_new', 'amount' => 2000, 'description' => 'Referral credit']], array_values($this->stripe->credits));
        $this->assertSame(ReferralCredit::STATUS_PENDING, ReferralCredit::query()->where('account_id', $referrer->id)->sole()->status);

        // The referrer's credit waits for their Stripe customer, then the hourly run (referrals:apply-credits) applies it.
        (new BillingAccount)->forceFill(['account_id' => $referrer->id, 'stripe_customer_id' => 'cus_acme', 'status' => 'none'])->save();
        $this->assertSame(1, app(Referrals::class)->applyPending());
        $this->assertSame(ReferralCredit::STATUS_APPLIED, ReferralCredit::query()->where('account_id', $referrer->id)->sole()->status);
        $this->assertCount(2, $this->stripe->credits);

        $this->actingAs($referrerOwner)->getJson('/api/app/account/billing')->assertOk()->assertJsonPath('referrals.qualified', 1)->assertJsonPath('referrals.earned_cents', 2000);
    }

    /**
     * Check that an account's own code and repeat referrals don't count.
     *
     * @return void
     */
    public function test_self_referrals_and_unknown_codes_are_ignored(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->withMember($owner)->create();
        $referrals = app(Referrals::class);
        $code = $referrals->codeFor($account);

        $this->assertNull($referrals->record($account, $owner, $code));
        $this->assertNull($referrals->record(Account::factory()->create(), $owner, 'nosuchcode'));
        $other = Account::factory()->create();
        $this->assertNotNull($referrals->record($other, $owner, strtoupper($code)));
        $this->assertNull($referrals->record($other, $owner, $code));
        $this->assertSame(1, Referral::query()->count());
    }

    /**
     * Post a signed Stripe webhook.
     *
     * @param  string  $id
     * @param  string  $type
     * @param  array<string, mixed>  $object
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function webhook(string $id, string $type, array $object): TestResponse
    {
        return $this->postJson('/webhooks/stripe', ['id' => $id, 'type' => $type, 'data' => ['object' => $object]], ['Stripe-Signature' => 'valid']);
    }
}
