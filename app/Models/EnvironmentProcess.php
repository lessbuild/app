<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A long-running process each deploy (re)starts as systemd units: queue workers, or the scheduler (always one).
 *
 * @property int $id
 * @property string $environment_id
 * @property string $name
 * @property string $type worker or scheduler
 * @property string $command
 * @property int $replicas
 * @property string $restart_policy always or on-failure
 * @property int $restart_delay_seconds
 * @property bool $is_enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class EnvironmentProcess extends Model
{
    /** @return BelongsTo<Environment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['replicas' => 'integer', 'restart_delay_seconds' => 'integer', 'is_enabled' => 'boolean'];
    }
}
