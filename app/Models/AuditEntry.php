<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuditAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An append-only record of who changed what. Kept for a year.
 *
 * @property string $id
 * @property string|null $account_id
 * @property string|null $project_id
 * @property string|null $actor_id
 * @property string|null $actor_name
 * @property string|null $actor_email
 * @property AuditAction $action
 * @property array<string, mixed>|null $context
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon $created_at
 */
class AuditEntry extends Model
{
    use HasUlids, MassPrunable;

    public const RETENTION_DAYS = 365;

    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * Reads `action` as an AuditAction and `context` as JSON.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'context' => 'array',
        ];
    }

    /**
     * Get the entries older than the retention period, which the model pruner deletes.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }
}
