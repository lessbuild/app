<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Something a signed-in person told us from inside the app: an idea, a problem, a question or praise. The message is
 * encrypted; platform admins read it and mark it resolved.
 *
 * @property int $id
 * @property string|null $user_id
 * @property string|null $account_id
 * @property string $kind one of KINDS
 * @property string $message encrypted
 * @property string|null $page the page they sent it from
 * @property string|null $resolved_by
 * @property CarbonImmutable|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read Account|null $account
 * @property-read User|null $resolver
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class Feedback extends Model
{
    /**
     * What the feedback is about, with how the form labels each.
     *
     * @var array<string, string>
     */
    public const KINDS = ['idea' => 'An idea', 'problem' => 'Something’s wrong', 'question' => 'A question', 'praise' => 'Something I like'];

    /**
     * The table, which is singular like the word.
     *
     * @var string
     */
    protected $table = 'feedback';

    /**
     * Get the person who sent it.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the account they were working in.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Get the admin who resolved it.
     *
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * Encrypts the message and reads the resolution time as an immutable date.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['message' => 'encrypted', 'resolved_at' => 'immutable_datetime'];
    }
}
