<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Someone who asked to be emailed when part of BuildPusher stops or starts working again. The address and unsubscribe
 * token are encrypted; only the confirmation token's hash is kept.
 *
 * @property int $id
 * @property string $email
 * @property string $email_hash
 * @property string|null $verification_token_hash
 * @property string $unsubscribe_token
 * @property CarbonImmutable|null $verified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Hidden(['email', 'verification_token_hash', 'unsubscribe_token'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class PlatformStatusSubscriber extends Model
{
    /**
     * Hash an email address into a lookup key, so a subscriber can be found without decrypting every row.
     *
     * @param  string  $email
     * @return string
     */
    public static function hashEmail(string $email): string
    {
        return hash('sha256', mb_strtolower(trim($email)));
    }

    /**
     * Get the attributes that should be cast.
     *
     * Encrypts the email address and the unsubscribe token.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['email' => 'encrypted', 'unsubscribe_token' => 'encrypted', 'verified_at' => 'immutable_datetime'];
    }
}
