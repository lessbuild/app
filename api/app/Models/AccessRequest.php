<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Someone asking to join while registration is closed. Their details are encrypted and found by a hash of their
 * email; an invitation stores only a hash of its one-time token.
 *
 * @property int $id
 * @property string $email_hash
 * @property string $email encrypted
 * @property string $name encrypted
 * @property string|null $company encrypted
 * @property string|null $team_size one of TEAM_SIZES
 * @property string $use_case encrypted
 * @property string $status one of STATUSES
 * @property string|null $review_notes encrypted; only admins see them
 * @property string|null $reviewed_by
 * @property CarbonImmutable|null $reviewed_at
 * @property string|null $invitation_token_hash
 * @property CarbonImmutable|null $invited_at
 * @property CarbonImmutable|null $invitation_expires_at
 * @property CarbonImmutable|null $accepted_at when they registered through the invitation
 * @property int|null $legacy_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $reviewer
 */
#[Hidden(['email_hash', 'invitation_token_hash'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class AccessRequest extends Model
{
    /**
     * Where a request can be: waiting, being talked to, invited, registered, or turned down.
     *
     * @var list<string>
     */
    public const STATUSES = ['pending', 'contacted', 'invited', 'accepted', 'declined'];

    /**
     * The team sizes the form offers.
     *
     * @var list<string>
     */
    public const TEAM_SIZES = ['1', '2-5', '6-20', '21-50', '51+'];

    /**
     * Get the admin who last reviewed it.
     *
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Hash an email the way requests are looked up by it.
     *
     * @param  string  $email
     * @return string
     */
    public static function hashEmail(string $email): string
    {
        return hash('sha256', mb_strtolower(trim($email)));
    }

    /**
     * Determine whether its invitation can still be used: invited, not yet accepted, and not expired.
     *
     * @return bool
     */
    public function invitationIsValid(): bool
    {
        return $this->status === 'invited' && $this->accepted_at === null && $this->invitation_expires_at?->isFuture() === true;
    }

    /**
     * Get the attributes that should be cast.
     *
     * Encrypts the requester's details and the review notes, and reads the timestamps as immutable dates.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email' => 'encrypted', 'name' => 'encrypted', 'company' => 'encrypted', 'use_case' => 'encrypted', 'review_notes' => 'encrypted',
            'reviewed_at' => 'immutable_datetime', 'invited_at' => 'immutable_datetime', 'invitation_expires_at' => 'immutable_datetime', 'accepted_at' => 'immutable_datetime',
        ];
    }
}
