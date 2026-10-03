<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in the platform operators' trail: admin rights granted or revoked, or something an admin did or looked at
 * in the admin panel.
 *
 * @property int $id
 * @property string|null $actor_id null when done from the command line
 * @property string $action e.g. admin.granted, customer.viewed, job.retried
 * @property string $source admin (the panel) or cli
 * @property string|null $subject_user_id
 * @property string|null $subject_account_id
 * @property string $description
 * @property CarbonImmutable $created_at
 * @property-read User|null $actor
 * @property-read User|null $subjectUser
 * @property-read Account|null $subjectAccount
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class PlatformAdminEvent extends Model
{
    use MassPrunable;

    /**
     * The admin trail is kept this many days (two years); `model:prune` deletes older entries.
     *
     * @var int
     */
    public const RETENTION_DAYS = 730;

    /**
     * Events are written once, so they only record when.
     *
     * @var string|null
     */
    public const UPDATED_AT = null;

    /**
     * Get the admin who did it.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Get the person it concerned, if any.
     *
     * @return BelongsTo<User, $this>
     */
    public function subjectUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    /**
     * Get the account it concerned, if any.
     *
     * @return BelongsTo<Account, $this>
     */
    public function subjectAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'subject_account_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * Reads `created_at` as an immutable date.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    /**
     * Get the entries old enough to delete.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }
}
