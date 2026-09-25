<?php

namespace App\Modules\Monitor\Models;

use App\Modules\Monitor\Database\Factories\IngestTokenFactory;
use App\Modules\Monitor\Database\MonitorModel as Model;
use App\Modules\Monitor\Models\Concerns\HasProjectVisibility;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'token_hash', 'prefix', 'expires_at'])]
#[Hidden(['token_hash'])]
class IngestToken extends Model
{
    /** @use HasFactory<IngestTokenFactory> */
    use HasFactory;

    use HasProjectVisibility;

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
