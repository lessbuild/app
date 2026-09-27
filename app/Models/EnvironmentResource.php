<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A backing service an environment's deploys connect to: the website's MySQL database, local Redis or Valkey (managed),
 * or anything external (PostgreSQL, object storage) described by its variables, which are written into `.env`.
 *
 * @property int $id
 * @property string $environment_id
 * @property string $name
 * @property string $type mysql, postgresql, redis, valkey or object_storage
 * @property bool $is_managed
 * @property array{variables?: array<string, string>, container_name?: string|null}|null $configuration encrypted
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Hidden(['configuration'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class EnvironmentResource extends Model
{
    public const TYPES = ['mysql' => 'MySQL', 'postgresql' => 'PostgreSQL', 'redis' => 'Redis', 'valkey' => 'Valkey', 'object_storage' => 'Object storage'];

    /** @return BelongsTo<Environment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_managed' => 'boolean', 'configuration' => 'encrypted:array'];
    }
}
