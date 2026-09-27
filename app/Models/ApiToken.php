<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * A Sanctum token bound to one account. Created only through CreateApiToken.
 *
 * @property int $id
 * @property string $tokenable_id
 * @property string $account_id
 * @property string $name
 * @property list<string> $abilities
 * @property Carbon|null $last_used_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property-read Account $account
 */
class ApiToken extends PersonalAccessToken
{
    /**
     * Sanctum's table, `personal_access_tokens`.
     */
    protected $table = 'personal_access_tokens';

    /**
     * Nothing: tokens are created by the action with forceFill, so request input can't set scopes or accounts.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * The account the token acts in.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
