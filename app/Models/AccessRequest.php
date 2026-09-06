<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessRequest extends Model
{
    public const STATUSES = ['pending', 'contacted', 'invited', 'accepted', 'declined'];

    public const TEAM_SIZES = ['1', '2-5', '6-20', '21-50', '51+'];

    protected $guarded = [];

    protected $hidden = ['email_hash'];

    protected $casts = [
        'email' => 'encrypted',
        'name' => 'encrypted',
        'company' => 'encrypted',
        'use_case' => 'encrypted',
        'review_notes' => 'encrypted',
        'reviewed_at' => 'datetime',
        'invited_at' => 'datetime',
        'invitation_expires_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Check invitation state, acceptance, and expiry before granting access.
     *
     * @return bool Whether an unaccepted invitation is still valid.
     */
    public function invitationIsValid(): bool
    {
        return $this->status === 'invited'
            && $this->accepted_at === null
            && $this->invitation_expires_at?->isFuture();
    }
}
