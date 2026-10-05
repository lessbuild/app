<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * How one part of BuildPusher fared on one day (UTC): how many times it was checked and how many of those found it not
 * working. The public status page draws its uptime bars from these.
 *
 * @property int $id
 * @property string $day The day, as Y-m-d (UTC).
 * @property string $component
 * @property int $checks
 * @property int $failures
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class PlatformStatusDay extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['checks' => 'integer', 'failures' => 'integer'];
    }
}
