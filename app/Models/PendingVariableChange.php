<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A change to an environment's variables waiting for a second person: save one, replace them all, or remove one.
 * The payload (which can hold secrets) is encrypted; the summary names what changes without values.
 *
 * @property int $id
 * @property string $environment_id
 * @property string $kind save, replace or delete
 * @property array<string, mixed> $payload
 * @property string $summary
 * @property string $status pending, approved or rejected
 * @property string|null $requested_by
 * @property string|null $decided_by
 * @property Carbon|null $decided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Environment $environment
 * @property-read User|null $requester
 */
class PendingVariableChange extends Model
{
    /**
     * Get the environment it changes.
     *
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * Get who asked for it.
     *
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * Encrypts the payload.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'decided_at' => 'datetime'];
    }
}
