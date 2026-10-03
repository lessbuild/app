<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A deploy a configuration asked for. Status: pending, blocked (a gate stopped it; retried each minute),
 * awaiting_approval, delivered (its build runs), succeeded, failed or canceled.
 *
 * @property int $id
 * @property int $configuration_application_id
 * @property string $environment_slug
 * @property string|null $environment_id
 * @property string $kind
 * @property string $status
 * @property string $intent_digest what it deploys; an identical later intent reuses this operation instead of redeploying
 * @property array{repository_id: int, repository_fingerprint: string} $payload encrypted
 * @property int|null $build_id
 * @property int $attempts
 * @property string|null $failure_code
 * @property int|null $retry_of_operation_id
 * @property int $retry_sequence
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ConfigurationApplication $application
 * @property-read Build|null $build
 * @property-read ConfigurationOperation|null $retry
 */
#[Hidden(['payload'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class ConfigurationOperation extends Model
{
    public const FINISHED = ['succeeded', 'failed', 'canceled'];

    /**
     * Get the application that started the operation.
     *
     * @return BelongsTo<ConfigurationApplication, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(ConfigurationApplication::class, 'configuration_application_id');
    }

    /**
     * Get the deploy it started, if any.
     *
     * @return BelongsTo<Build, $this>
     */
    public function build(): BelongsTo
    {
        return $this->belongsTo(Build::class);
    }

    /**
     * Get the operation this one retries (`retry_of_operation_id`).
     *
     * @return HasOne<ConfigurationOperation, $this>
     */
    public function retry(): HasOne
    {
        return $this->hasOne(self::class, 'retry_of_operation_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * Encrypts `payload`, which can carry variable values.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'attempts' => 'integer', 'retry_sequence' => 'integer', 'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }
}
