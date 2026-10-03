<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A variable an environment's deploys write into `.env` (runtime), export while building (build), or both (all).
 * Values are encrypted; each change keeps a version. Secrets are never shown again after saving.
 *
 * @property int $id
 * @property string $environment_id
 * @property string|null $updated_by
 * @property string $key
 * @property string $value
 * @property bool $is_secret
 * @property string $scope runtime, build or all
 * @property int|null $secret_sync_id the password manager sync that manages it, if any
 * @property int $current_version
 * @property CarbonImmutable|null $rotated_at
 * @property CarbonImmutable|null $rotation_due_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Environment $environment
 */
#[Hidden(['value'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class EnvironmentVariable extends Model
{
    public const SCOPES = ['runtime' => 'Runtime (.env)', 'build' => 'Build only', 'all' => 'Build and runtime'];

    /**
     * Get the environment the variable belongs to.
     *
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * Get the variable's earlier values, so a change can be traced or undone.
     *
     * @return HasMany<EnvironmentVariableVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(EnvironmentVariableVersion::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * Encrypts `value`.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['value' => 'encrypted', 'is_secret' => 'boolean', 'current_version' => 'integer', 'rotated_at' => 'immutable_datetime', 'rotation_due_at' => 'immutable_datetime'];
    }
}
