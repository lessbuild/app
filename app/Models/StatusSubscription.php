<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatusSubscription extends Model
{
    protected $guarded = [];

    protected $hidden = ['email', 'email_hash', 'verification_token_hash', 'unsubscribe_token'];

    protected $casts = [
        'email' => 'encrypted',
        'unsubscribe_token' => 'encrypted',
        'verified_at' => 'datetime',
    ];

    public function statusPage(): BelongsTo
    {
        return $this->belongsTo(StatusPage::class);
    }
}
