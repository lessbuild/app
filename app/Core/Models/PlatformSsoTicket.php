<?php

namespace App\Core\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PlatformSsoTicket extends Model
{
    use HasUlids;

    protected $connection = 'core';

    protected $table = 'platform_sso_tickets';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'token_hash',
        'auth_session_id',
        'user_id',
        'issuer_origin',
        'audience_origin',
        'return_url',
        'expires_at',
        'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PlatformAuthSession, $this> */
    public function authSession(): BelongsTo
    {
        return $this->belongsTo(PlatformAuthSession::class, 'auth_session_id');
    }

    /** @return BelongsTo<PlatformUser, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'user_id');
    }
}
