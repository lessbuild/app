<?php

namespace App\Core\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PlatformAuthSession extends Model
{
    use HasUlids;

    protected $connection = 'core';

    protected $table = 'platform_auth_sessions';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'remember_token_hash',
        'remembered',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'remembered' => 'boolean',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PlatformUser, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'user_id');
    }
}
