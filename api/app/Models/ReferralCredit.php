<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Credit one side of a referral earned. Pending until it's added to the account's Stripe balance; applied after.
 *
 * @property int $id
 * @property int $referral_id
 * @property string $account_id
 * @property int $amount_cents
 * @property string $status pending or applied
 * @property string|null $provider_reference the Stripe balance transaction
 * @property string|null $last_error why the last attempt to apply it failed
 * @property Carbon|null $applied_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Referral $referral
 * @property-read Account $account
 */
class ReferralCredit extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPLIED = 'applied';

    /**
     * Get the referral that earned it.
     *
     * @return BelongsTo<Referral, $this>
     */
    public function referral(): BelongsTo
    {
        return $this->belongsTo(Referral::class);
    }

    /**
     * Get the account it's for.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * Reads when it was applied as a date.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['applied_at' => 'datetime', 'amount_cents' => 'integer'];
    }
}
