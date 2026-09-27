<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\IngestTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $environment_id
 * @property string|null $created_by
 * @property string $name
 * @property string $token_hash
 * @property string $prefix
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $last_used_at
 * @property int|null $legacy_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Environment $environment
 * @property-read User|null $creator
 */
#[Fillable(['name', 'token_hash', 'prefix', 'expires_at'])]
#[Hidden(['token_hash'])]
#[UseFactory(IngestTokenFactory::class)]
final class IngestToken extends Model
{
    /** @use HasFactory<IngestTokenFactory> */
    use HasFactory;

    /** @return BelongsTo<Environment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @param Builder<IngestToken> $query */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull('revoked_at')
            ->where(fn (Builder $expiry) => $expiry->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function status(): string
    {
        if ($this->revoked_at !== null) {
            return 'revoked';
        }

        return $this->expires_at !== null && ! $this->expires_at->isFuture() ? 'expired' : 'active';
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
        ];
    }
}
