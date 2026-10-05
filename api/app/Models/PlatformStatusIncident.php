<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A stretch of time one part of BuildPusher wasn't working, opened and resolved by the status checks themselves.
 *
 * @property int $id
 * @property string $component
 * @property string $name The part's name when it opened.
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class PlatformStatusIncident extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['started_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime'];
    }
}
