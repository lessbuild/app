<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Contracts\PaymentProvider;
use App\Models\Account;
use App\Models\BillingAccount;
use App\Models\Referral;
use App\Models\ReferralCredit;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Refer a friend: each account shares a link; an account that signs up through it and then pays earns both sides
 * credit on their Stripe balance, which comes off their next invoices.
 */
final class Referrals
{
    /**
     * Create a new Referrals instance.
     *
     * @param  PaymentProvider  $payments  Holds the credit on each customer's balance.
     */
    public function __construct(private readonly PaymentProvider $payments) {}

    /**
     * Get the account's share code, making one the first time it's asked for.
     *
     * @param  Account  $account
     * @return string
     */
    public function codeFor(Account $account): string
    {
        while ($account->referral_code === null) {
            try {
                $account->forceFill(['referral_code' => Str::lower(Str::random(10))])->save();
            } catch (UniqueConstraintViolationException) {
                $account->referral_code = null;
            }
        }

        return $account->referral_code;
    }

    /**
     * Record that a new account signed up with a share code. Unknown codes, an account's own code and accounts
     * already referred are ignored.
     *
     * @param  Account  $referred  the new account
     * @param  User  $user  who signed up
     * @param  string|null  $code
     * @return Referral|null
     */
    public function record(Account $referred, User $user, ?string $code): ?Referral
    {
        $code = Str::lower(trim((string) $code));
        if ($code === '') {
            return null;
        }
        $referrer = Account::query()->where('referral_code', $code)->first();
        if ($referrer === null || $referrer->id === $referred->id || Referral::query()->where('referred_account_id', $referred->id)->exists()) {
            return null;
        }
        $referral = new Referral;
        $referral->forceFill(['referrer_account_id' => $referrer->id, 'referred_account_id' => $referred->id, 'referred_user_id' => $user->id])->save();

        return $referral;
    }

    /**
     * Qualify the account's referral now that it pays: both sides earn the credit, and it's applied at once where
     * the account already has a Stripe customer. Does nothing when the account wasn't referred or already qualified.
     *
     * @param  Account  $referred
     * @return void
     */
    public function qualify(Account $referred): void
    {
        $referral = DB::transaction(function () use ($referred): ?Referral {
            $referral = Referral::query()->where('referred_account_id', $referred->id)->whereNull('qualified_at')->lockForUpdate()->first();
            if ($referral === null) {
                return null;
            }
            $referral->forceFill(['qualified_at' => now()])->save();
            foreach ([$referral->referrer_account_id, $referral->referred_account_id] as $accountId) {
                (new ReferralCredit)->forceFill([
                    'referral_id' => $referral->id, 'account_id' => $accountId,
                    'amount_cents' => max(0, (int) config('billing.referral_credit_cents')), 'status' => ReferralCredit::STATUS_PENDING,
                ])->save();
            }

            return $referral;
        });
        if ($referral !== null) {
            $this->applyPending([$referral->referrer_account_id, $referral->referred_account_id]);
        }
    }

    /**
     * Add pending credits to their accounts' Stripe balances. Accounts without a Stripe customer keep theirs pending
     * until they have one; failures are recorded and retried on the next run. Returns how many were applied.
     *
     * @param  list<string>|null  $accountIds  only these accounts
     * @return int
     */
    public function applyPending(?array $accountIds = null): int
    {
        $applied = 0;
        $credits = ReferralCredit::query()->where('status', ReferralCredit::STATUS_PENDING)
            ->when($accountIds !== null, fn ($query) => $query->whereIn('account_id', $accountIds))
            ->orderBy('id')->limit(200)->get();
        foreach ($credits as $credit) {
            $customer = BillingAccount::query()->whereKey($credit->account_id)->value('stripe_customer_id');
            if (! is_string($customer) || $customer === '' || $credit->amount_cents === 0) {
                continue;
            }
            try {
                $reference = $this->payments->creditBalance($customer, $credit->amount_cents, __('Referral credit'), 'referral-credit-'.$credit->id);
            } catch (Throwable $exception) {
                report($exception);
                $credit->forceFill(['last_error' => Str::limit($exception->getMessage(), 250)])->save();

                continue;
            }
            $credit->forceFill(['status' => ReferralCredit::STATUS_APPLIED, 'provider_reference' => $reference, 'last_error' => null, 'applied_at' => now()])->save();
            $applied++;
        }

        return $applied;
    }

    /**
     * Sum up an account's referrals for its billing page.
     *
     * @param  Account  $account
     * @return array{link: string, signed_up: int, qualified: int, earned_cents: int, pending_cents: int, credit_cents: int}
     */
    public function summary(Account $account): array
    {
        $referrals = Referral::query()->where('referrer_account_id', $account->id);
        $credits = ReferralCredit::query()->where('account_id', $account->id);

        return [
            'link' => route('referrals.show', $this->codeFor($account)),
            'signed_up' => (clone $referrals)->count(),
            'qualified' => (clone $referrals)->whereNotNull('qualified_at')->count(),
            'earned_cents' => (int) (clone $credits)->where('status', ReferralCredit::STATUS_APPLIED)->sum('amount_cents'),
            'pending_cents' => (int) (clone $credits)->where('status', ReferralCredit::STATUS_PENDING)->sum('amount_cents'),
            'credit_cents' => max(0, (int) config('billing.referral_credit_cents')),
        ];
    }
}
