<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\SocialProvider;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A provider account (GitHub, GitLab, Bitbucket) the user can sign in with. Nothing is mass-assignable.
 *
 * @property string $id
 * @property string $user_id
 * @property SocialProvider $provider
 * @property string $provider_user_id
 * @property string|null $email
 * @property Carbon|null $last_used_at
 * @property Carbon|null $created_at
 */
class SocialIdentity extends Model
{
    use HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provider' => SocialProvider::class,
            'last_used_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
