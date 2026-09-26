<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\SignInMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * One sign-in attempt on a known account, kept for 90 days.
 *
 * @property string $id
 * @property string $user_id
 * @property bool $succeeded
 * @property SignInMethod|null $method
 * @property bool $two_factor
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property \Illuminate\Support\Carbon $created_at
 */
class SignInEvent extends Model
{
    use HasUlids, MassPrunable;

    public const RETENTION_DAYS = 90;

    public const UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'succeeded' => 'boolean',
            'method' => SignInMethod::class,
            'two_factor' => 'boolean',
        ];
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }
}
