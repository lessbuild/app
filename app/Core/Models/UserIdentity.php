<?php

namespace App\Core\Models;

use App\Core\Database\CoreModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserIdentity extends CoreModel
{
    protected $table = 'user_identities';

    protected $fillable = [
        'user_id',
        'provider',
        'provider_user_id',
        'provider_email',
        'verified_at',
        'status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<PlatformUser, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'user_id');
    }
}
