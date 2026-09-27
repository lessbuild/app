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
 * A configuration document and its bindings, planned and frozen for 15 minutes. Only its requester can apply it, and
 * only while the plan still matches the project exactly (the plan fingerprint).
 *
 * @property int $id
 * @property string $project_id
 * @property string $requested_by
 * @property string $document YAML (encrypted)
 * @property array{placements?: array<string, int>, secrets?: array<string, int>, repositories?: array<string, int>} $bindings encrypted
 * @property array{version: int, project_id: string, changes: list<array<string, mixed>>, fingerprint: string, omitted_objects: string, apply_available: bool} $summary
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $applied_at
 * @property int|null $legacy_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read User $requester
 * @property-read ConfigurationApplication|null $application
 */
#[Hidden(['document', 'bindings'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class ConfigurationReview extends Model
{
    /**
     * The project the configuration belongs to.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Who asked for the review (`requested_by`).
     *
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * The application created when the review was applied.
     *
     * @return HasOne<ConfigurationApplication, $this>
     */
    public function application(): HasOne
    {
        return $this->hasOne(ConfigurationApplication::class);
    }

    /**
     * Encrypts the document and its bindings (they can contain secrets); reads `summary` as JSON.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['document' => 'encrypted', 'bindings' => 'encrypted:array', 'summary' => 'array', 'expires_at' => 'immutable_datetime', 'applied_at' => 'immutable_datetime'];
    }
}
