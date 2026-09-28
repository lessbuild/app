<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A switch for unfinished or risky behaviour: off, on for everyone, or on for chosen accounts. Code asks
 * `FeatureFlags::enabled()`; a key with no flag is off.
 *
 * @property int $id
 * @property string $key lower-case letters, digits, dots and dashes
 * @property string $description
 * @property string $state off, on or accounts
 * @property list<string>|null $account_ids the accounts it's on for, when the state is accounts
 * @property string|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $editor
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class FeatureFlag extends Model
{
    /**
     * The states a flag can be in.
     *
     * @var list<string>
     */
    public const STATES = ['off', 'on', 'accounts'];

    /**
     * Get who last changed it.
     *
     * @return BelongsTo<User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Determine whether the flag is on for an account (or, with none, on for everyone).
     *
     * @param  Account|null  $account
     * @return bool
     */
    public function isOnFor(?Account $account): bool
    {
        return match ($this->state) {
            'on' => true,
            'accounts' => $account !== null && in_array($account->id, $this->account_ids ?? [], true),
            default => false,
        };
    }

    /**
     * Get the attributes that should be cast.
     *
     * Reads the account IDs as a JSON list.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['account_ids' => 'array'];
    }
}
