<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A device that gets a person's push notifications: the browser's push service address and the keys that encrypt
 * messages for it (encrypted at rest).
 *
 * @property int $id
 * @property string $user_id
 * @property string $endpoint
 * @property string $endpoint_hash
 * @property string $public_key the browser's P-256 key, base64url
 * @property string $auth_secret base64url
 * @property string|null $device such as iPhone · Safari
 * @property Carbon|null $last_used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
final class PushSubscription extends Model
{
    /**
     * The attributes that can't be mass assigned: all of them; subscriptions are written with forceFill.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * Get the attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['endpoint' => 'encrypted', 'public_key' => 'encrypted', 'auth_secret' => 'encrypted', 'last_used_at' => 'datetime'];
    }

    /**
     * Get the person the device belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
