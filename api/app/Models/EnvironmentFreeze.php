<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A period when an environment takes no deploys, such as a holiday or launch freeze.
 *
 * @property int $id
 * @property string $environment_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string|null $reason shown to people who try to deploy
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Environment $environment
 */
class EnvironmentFreeze extends Model
{
    /**
     * Get the environment that's frozen.
     *
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * Reads the period as dates.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }
}
