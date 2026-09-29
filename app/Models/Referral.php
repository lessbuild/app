<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An account that signed up through another account's share link. It qualifies, and both earn credit, when the new
 * account first pays.
 *
 * @property int $id
 * @property string $referrer_account_id
 * @property string $referred_account_id
 * @property string|null $referred_user_id
 * @property Carbon|null $qualified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Account $referrer
 * @property-read Account $referred
 */
class Referral extends Model
{
    /**
     * Get the account whose link was used.
     *
     * @return BelongsTo<Account, $this>
     */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'referrer_account_id');
    }

    /**
     * Get the account that signed up.
     *
     * @return BelongsTo<Account, $this>
     */
    public function referred(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'referred_account_id');
    }

    /**
     * Get the credits the referral earned.
     *
     * @return HasMany<ReferralCredit, $this>
     */
    public function credits(): HasMany
    {
        return $this->hasMany(ReferralCredit::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * Reads when it qualified as a date.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['qualified_at' => 'datetime'];
    }
}
