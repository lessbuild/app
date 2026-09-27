<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\StatusSubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Someone who asked for status update emails. The address and unsubscribe token are encrypted; only the confirmation token's hash is kept.
 *
 * @property int $id
 * @property int $status_page_id
 * @property string $email
 * @property string $email_hash
 * @property string|null $verification_token_hash
 * @property string $unsubscribe_token
 * @property CarbonImmutable|null $verified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read StatusPage $statusPage
 */
#[Hidden(['email', 'verification_token_hash', 'unsubscribe_token'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
#[UseFactory(StatusSubscriptionFactory::class)]
class StatusSubscription extends Model
{
    /** @use HasFactory<StatusSubscriptionFactory> */
    use HasFactory;

    public static function hashEmail(string $email): string
    {
        return hash('sha256', mb_strtolower(trim($email)));
    }

    /** @return BelongsTo<StatusPage, $this> */
    public function statusPage(): BelongsTo
    {
        return $this->belongsTo(StatusPage::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['email' => 'encrypted', 'unsubscribe_token' => 'encrypted', 'verified_at' => 'immutable_datetime'];
    }
}
