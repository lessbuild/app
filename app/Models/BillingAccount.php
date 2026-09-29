<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $account_id
 * @property string|null $stripe_customer_id
 * @property string|null $stripe_subscription_id
 * @property string $status none, incomplete, trialing, active, past_due, unpaid, canceled
 * @property string $interval month or year: how often the whole subscription is billed
 * @property Carbon|null $current_period_end
 */
class BillingAccount extends Model
{
    /**
     * One billing record per account, keyed by the account's ID.
     *
     * @var string
     */
    protected $primaryKey = 'account_id';

    /**
     * The key is the account's ULID, not a sequence.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * ULIDs are strings.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * Get the attributes that should be cast.
     *
     * Plain columns; dates come back as Carbon.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['current_period_end' => 'datetime'];
    }

    /**
     * Get the account's billing row, creating it (without Stripe) on first use.
     *
     * @param  string  $accountId
     * @return BillingAccount
     */
    public static function forAccount(string $accountId): self
    {
        $billing = self::query()->find($accountId);
        if ($billing === null) {
            $billing = new self;
            $billing->forceFill(['account_id' => $accountId, 'status' => 'none', 'interval' => 'month'])->save();
        }

        return $billing;
    }

    /**
     * Determine whether a new subscription gets the free trial: only the account's first one.
     *
     * @return bool
     */
    public function trialAvailable(): bool
    {
        return (int) config('billing.trial_days') > 0 && $this->stripe_subscription_id === null && in_array($this->status, ['none', 'incomplete'], true);
    }

    /**
     * Determine whether there's a subscription still billing (active, trialing or past due) that changes must be
     * synced to.
     *
     * @return bool
     */
    public function hasLiveSubscription(): bool
    {
        return $this->stripe_subscription_id !== null && in_array($this->status, ['active', 'trialing', 'past_due'], true);
    }
}
