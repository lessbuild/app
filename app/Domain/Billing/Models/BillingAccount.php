<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $account_id
 * @property string|null $stripe_customer_id
 * @property string|null $stripe_subscription_id
 * @property string $status none, incomplete, trialing, active, past_due, unpaid, canceled
 * @property Carbon|null $current_period_end
 */
class BillingAccount extends Model
{
    protected $primaryKey = 'account_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['current_period_end' => 'datetime'];
    }

    /** The account's billing row, created (without Stripe) on first use. */
    public static function forAccount(string $accountId): self
    {
        $billing = self::query()->find($accountId);
        if ($billing === null) {
            $billing = new self;
            $billing->forceFill(['account_id' => $accountId, 'status' => 'none'])->save();
        }

        return $billing;
    }

    public function hasLiveSubscription(): bool
    {
        return $this->stripe_subscription_id !== null && in_array($this->status, ['active', 'trialing', 'past_due'], true);
    }
}
