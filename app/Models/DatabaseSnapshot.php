<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One look at a website's database: its size, open connections and tables. Kept for 30 days.
 *
 * @property int $id
 * @property int $website_id
 * @property string|null $requested_by null when the daily inspection took it
 * @property string $status queued, running, ready or failed
 * @property int|null $size_bytes
 * @property int|null $active_connections
 * @property list<string>|null $tables
 * @property string|null $error
 * @property CarbonImmutable|null $collected_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Website $website
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class DatabaseSnapshot extends Model
{
    /**
     * The website whose database was inspected.
     *
     * @return BelongsTo<Website, $this>
     */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /**
     * Reads `tables` as a JSON list.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'active_connections' => 'integer', 'tables' => 'array', 'collected_at' => 'immutable_datetime'];
    }
}
