<?php

namespace App\Core\Models;

use App\Core\Database\CoreModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Passkey extends CoreModel
{
    protected $fillable = [
        'user_id',
        'name',
        'credential_id',
        'credential',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'credential' => 'array',
            'last_used_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PlatformUser, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'user_id');
    }
}
