<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountRole;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Only a SHA-256 hash of the invitation token is stored; the plain token appears once, in the email.
 *
 * @property string $id
 * @property string $account_id
 * @property string $email
 * @property AccountRole $role
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 * @property-read Account $account
 * @property-read User|null $invitedBy
 */
class AccountInvitation extends Model
{
    use HasUlids;

    /**
     * Get the attributes that should be cast.
     *
     * Reads `role` as an AccountRole.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => AccountRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Get the account the invitation is to.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Get the person who sent the invitation (`invited_by_id`).
     *
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_id');
    }

    /**
     * Determine whether the invitation can still be accepted: not accepted, not revoked and not expired.
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /**
     * Limit a query to invitations that can still be accepted.
     *
     * @param  Builder<self>  $query
     * @return void
     */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    /**
     * Hash an invitation token into its stored form. Only the hash is kept, so a database leak doesn't expose working
     * links.
     *
     * @param  string  $token
     * @return string
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
