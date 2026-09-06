<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerImportAssessment extends Model
{
    protected $guarded = [];

    protected $hidden = ['token_hash', 'configuration'];

    protected $casts = [
        'configuration' => 'encrypted:array',
        'report' => 'encrypted:array',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    /**
     * Validate import assessment ownership, workspace, expiry, and secret token.
     *
     * @param  User  $user  The account attempting to consume the assessment.
     * @param  string  $token  The plaintext token whose digest is compared in constant time.
     * @return bool Whether the unconsumed assessment is usable by this account.
     */
    public function isUsableBy(User $user, string $token): bool
    {
        return $this->user_id === $user->id
            && $this->organization_id === $user->current_organization_id
            && $this->consumed_at === null
            && $this->expires_at->isFuture()
            && hash_equals($this->token_hash, hash('sha256', $token));
    }
}
